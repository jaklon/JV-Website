package main

import (
	"context"
	"encoding/json"
	"net/http"
	"net/http/httptest"
	"strings"
	"sync/atomic"
	"testing"
	"time"
)

func fixture() Input {
	return Input{Question: "Kapan kita mulai bersama?", Today: "2026-10-08", Sources: []Source{{Key: "profile:1", Kind: "profile", ID: "1", Title: "Tentang kita", Text: "Mulai hubungan: 2024-02-14"}}}
}
func fakeEngine(t *testing.T, handler http.HandlerFunc) (*engine, func()) {
	t.Helper()
	server := httptest.NewServer(handler)
	return &engine{url: server.URL, client: server.Client()}, server.Close
}
func TestInputRejectsUnexpectedData(t *testing.T) {
	in := fixture()
	if !validInput(in) {
		t.Fatal("fixture invalid")
	}
	in.History = []Turn{{Role: "system", Content: "leak"}}
	if validInput(in) {
		t.Fatal("system history accepted")
	}
	in = fixture()
	in.Sources[0].Key = "letter:draft"
	if validInput(in) {
		t.Fatal("invalid source accepted")
	}
	in = fixture()
	in.Question = strings.Repeat("a", 2001)
	if validInput(in) {
		t.Fatal("oversized question accepted")
	}
}
func TestTokenTrimmingPreservesQuestion(t *testing.T) {
	e, close := fakeEngine(t, func(w http.ResponseWriter, r *http.Request) {
		if r.URL.Path == "/apply-template" {
			var p struct {
				Messages []Turn `json:"messages"`
			}
			json.NewDecoder(r.Body).Decode(&p)
			b, _ := json.Marshal(p.Messages)
			reply(w, 200, map[string]string{"prompt": string(b)})
			return
		}
		var p struct {
			Content string `json:"content"`
		}
		json.NewDecoder(r.Body).Decode(&p)
		n := 500
		if strings.Contains(p.Content, "old old") {
			n = 2000
		}
		reply(w, 200, map[string]any{"tokens": make([]int, n)})
	})
	defer close()
	in := fixture()
	in.History = []Turn{{Role: "user", Content: "old old history"}}
	turns, _, n, err := e.fit(context.Background(), in)
	if err != nil || n > 1792 || len(turns) != 2 || !strings.Contains(turns[1].Content, in.Question) {
		t.Fatal("failed to trim old history", err)
	}
}
func TestGenerationLimitsAndUnknownSources(t *testing.T) {
	var captured map[string]any
	answer := "Mulai hubungan: 2024-02-14"
	e, close := fakeEngine(t, func(w http.ResponseWriter, r *http.Request) {
		switch r.URL.Path {
		case "/apply-template":
			reply(w, 200, map[string]string{"prompt": "fixture"})
		case "/tokenize":
			reply(w, 200, map[string]any{"tokens": []int{1, 2, 3}})
		default:
			json.NewDecoder(r.Body).Decode(&captured)
			reply(w, 200, map[string]any{"choices": []any{map[string]any{"message": Turn{Content: answer}, "finish_reason": "stop"}}, "usage": map[string]int{"prompt_tokens": 3, "completion_tokens": 20}})
		}
	})
	defer close()
	result, err := e.generate(context.Background(), fixture())
	if err != nil || !strings.Contains(result.Answer, "2024-02-14") || !result.ModelCalled || result.SourceKeys[0] != "profile:1" {
		t.Fatal(err)
	}
	if captured["max_tokens"] != float64(256) || captured["stream"] != false || captured["reasoning_effort"] != "none" {
		t.Fatal("generation limits missing")
	}
	answer = `{"answer":"Tebakan.","source_keys":["letter:aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa"]}`
	result, err = e.generate(context.Background(), fixture())
	if err != nil || !result.UsedFallback || strings.Contains(result.Answer, "Tebakan") || result.SourceKeys[0] != "profile:1" {
		t.Fatal("unsupported answer/citation escaped evidence validation")
	}
}
func TestGatewayTimeoutAbortsWorkerAndRejectsBusy(t *testing.T) {
	var aborts atomic.Int32
	e, close := fakeEngine(t, func(w http.ResponseWriter, r *http.Request) {
		select {
		case <-r.Context().Done():
		case <-time.After(100 * time.Millisecond):
		}
	})
	defer close()
	e.abort = func() { aborts.Add(1) }
	g := &gateway{engine: e, busy: make(chan struct{}, 1), timeout: 20 * time.Millisecond}
	body, _ := json.Marshal(fixture())
	w := httptest.NewRecorder()
	g.ServeHTTP(w, httptest.NewRequest("POST", "/chat", strings.NewReader(string(body))))
	if w.Code != 503 || aborts.Load() != 1 {
		t.Fatal("failed to abort timed-out inference")
	}
	g.busy <- struct{}{}
	w = httptest.NewRecorder()
	g.ServeHTTP(w, httptest.NewRequest("POST", "/chat", strings.NewReader(string(body))))
	if w.Code != 409 {
		t.Fatal("accepted concurrent inference")
	}
	<-g.busy
}
func TestGatewayOnlyAllowsWhitelistedPaths(t *testing.T) {
	g := &gateway{}
	for _, path := range []string{"/v1/chat/completions", "/tokenize", "/props", "/tools"} {
		w := httptest.NewRecorder()
		g.ServeHTTP(w, httptest.NewRequest("GET", path, nil))
		if w.Code != 404 {
			t.Fatal(path)
		}
	}
}
