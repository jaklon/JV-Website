package main

// Retrieval and factual rendering are deliberately independent of generation.
// Never let a small model invent a date or choose a citation URL.
import (
	"regexp"
	"sort"
	"strconv"
	"strings"
	"time"
	"unicode"
)

var wordsRE = regexp.MustCompile(`[\pL\pN]+`)
var dateRE = regexp.MustCompile(`\b(20\d{2})-(\d{2})-(\d{2})\b`)
var yearRE = regexp.MustCompile(`\b(20\d{2})\b`)
var months = []string{"januari", "februari", "maret", "april", "mei", "juni", "juli", "agustus", "september", "oktober", "november", "desember"}
var stopWords = strings.Fields("apa siapa kapan berapa di ke dari dan atau yang kita kami kamu aku saya ini itu tadi tersebut nya tentang saat waktu pada dalam ada mana bagaimana tolong coba dong ingin mau boleh cerita kenangan hari bulan tahun lalu sekarang tanggal kota warna nama lokasi isi deskripsi hubungan pasangan jv story judul lakukan melihat membawa bawa untuk liburan perjalanan")

func containsAny(s string, values ...string) bool {
	for _, v := range values {
		if strings.Contains(s, v) {
			return true
		}
	}
	return false
}
func stem(w string) string {
	w = strings.TrimSuffix(w, "nya")
	switch w {
	case "kameranya":
		return "kamera"
	case "membawa", "dibawa", "bawanya":
		return "bawa"
	case "makanan", "memakan", "dimakan":
		return "makan"
	case "bermain", "permainan":
		return "main"
	case "melihat", "dilihat":
		return "lihat"
	case "menggunakan":
		return "pakai"
	}
	return w
}
func terms(s string) []string {
	out := []string{}
	seen := map[string]bool{}
	for _, w := range wordsRE.FindAllString(strings.ToLower(s), -1) {
		w = stem(w)
		skip := len([]rune(w)) < 3
		for _, stop := range stopWords {
			if w == stop {
				skip = true
				break
			}
		}
		if !skip && !seen[w] {
			out = append(out, w)
			seen[w] = true
		}
	}
	return out
}
func matches(text, term string) bool {
	for _, w := range wordsRE.FindAllString(strings.ToLower(text), -1) {
		if stem(w) == term {
			return true
		}
	}
	return false
}
func temporalRange(q, today string) (time.Time, time.Time, bool) {
	now, err := time.Parse("2006-01-02", today)
	if err != nil {
		return time.Time{}, time.Time{}, false
	}
	year := now.Year()
	if m := yearRE.FindString(q); m != "" {
		year, _ = strconv.Atoi(m)
	}
	if exact := dateRE.FindString(q); exact != "" {
		if d, err := time.Parse("2006-01-02", exact); err == nil {
			return d, d.AddDate(0, 0, 1), true
		}
	}
	month := 0
	if strings.Contains(q, "bulan lalu") {
		d := time.Date(now.Year(), now.Month(), 1, 0, 0, 0, 0, time.UTC).AddDate(0, -1, 0)
		year = d.Year()
		month = int(d.Month())
	}
	if strings.Contains(q, "bulan ini") {
		month = int(now.Month())
		year = now.Year()
	}
	for i, name := range months {
		if matches(q, name) {
			month = i + 1
			break
		}
	}
	if month > 0 {
		d := time.Date(year, time.Month(month), 1, 0, 0, 0, 0, time.UTC)
		return d, d.AddDate(0, 1, 0), true
	}
	if strings.Contains(q, "tahun lalu") {
		year = now.Year() - 1
	} else if !yearRE.MatchString(q) && !strings.Contains(q, "tahun ini") {
		return time.Time{}, time.Time{}, false
	}
	d := time.Date(year, 1, 1, 0, 0, 0, 0, time.UTC)
	return d, d.AddDate(1, 0, 0), true
}
func followupQuestion(in Input) string {
	q := in.Question
	lower := strings.ToLower(q)
	if containsAny(lower, "nya", "itu", "tadi", "tersebut", "lanjut") {
		for i := len(in.History) - 1; i >= 0; i-- {
			if in.History[i].Role == "user" {
				q += " " + in.History[i].Content
				break
			}
		}
	}
	return q
}
func isConversation(q string) bool {
	q = strings.ToLower(q)
	if containsAny(q, "album", "surat", "kenangan", "anniversary", "ulang tahun", "tanggal", "kapan kita", "tagline", "profil", "nama pasangan") {
		return false
	}
	if containsAny(q, "ingin tahu", "mau tahu", "apa yang kita", "apa bekal", "warna ", "siapa membawa", "naik apa", "di mana kita", "dimana kita", "kapan ") {
		return false
	}
	// A personal disclosure is conversational even if it mentions an existing place/name.
	return containsAny(q, "aku ", "saya ", "boleh cerita", "ingin cerita", "mau cerita", "temani", "teman ngobrol") || strings.HasPrefix(q, "hai") || strings.HasPrefix(q, "halo") || strings.HasPrefix(q, "dia ") || strings.HasPrefix(q, "rasanya ")
}
func requestedKind(q string) string {
	if containsAny(q, "album") {
		return "album"
	}
	if containsAny(q, "surat") {
		return "letter"
	}
	if containsAny(q, "ulang tahun", "ultah", "pernikahan") {
		return "date"
	}
	if containsAny(q, "tagline", "nama pasangan", "nama kita", "nama kalian", "mulai hubungan", "mulai bersama", "mulai berpacaran", "sejak kapan", "anniversary") {
		return "profile"
	}
	return "memory"
}

type rankedSource struct {
	source Source
	score  int
}

func selectSources(in Input) []Source {
	q := strings.ToLower(followupQuestion(in))
	kind := requestedKind(q)
	from, to, dated := temporalRange(q, in.Today)
	keywords := terms(q)
	ranked := []rankedSource{}
	for _, s := range in.Sources {
		if s.Kind != kind {
			continue
		}
		if dated && kind == "memory" {
			d, err := time.Parse("2006-01-02", s.Date)
			if err != nil || d.Before(from) || !d.Before(to) {
				continue
			}
		}
		score := 0
		for _, w := range keywords {
			if matches(s.Title, w) {
				score += 5
			}
			if matches(s.Text, w) {
				score++
			}
		}
		if kind == "profile" {
			score += 10
		}
		if kind == "letter" && len(keywords) == 0 {
			score++
		}
		if kind == "memory" && dated {
			score += 3
		}
		if score > 0 {
			ranked = append(ranked, rankedSource{s, score})
		}
	}
	sort.SliceStable(ranked, func(i, j int) bool {
		if ranked[i].score != ranked[j].score {
			return ranked[i].score > ranked[j].score
		}
		return ranked[i].source.Date > ranked[j].source.Date
	})
	out := []Source{}
	// Date browsing returns multiple actual memories; a specific question gets one best record.
	list := dated && kind == "memory" && containsAny(q, "kenangan", "momen") && !containsAny(q, "kapan", "tanggal berapa")
	for i, r := range ranked {
		if i > 0 && !list {
			break
		}
		out = append(out, r.source)
	}
	return out
}
func sentences(s string) []string {
	parts := regexp.MustCompile(`[\n.;]+`).Split(s, -1)
	out := []string{}
	for _, p := range parts {
		if p = strings.TrimSpace(p); p != "" {
			out = append(out, p+".")
		}
	}
	return out
}
func labelled(text, label string) string {
	for _, s := range sentences(text) {
		if strings.HasPrefix(strings.ToLower(s), strings.ToLower(label)+":") {
			return s
		}
	}
	return ""
}
func excerpt(s Source, q string) string {
	lower := strings.ToLower(q)
	if containsAny(lower, "kapan", "tanggal berapa", "tanggal liburan") && s.Date != "" {
		return "Tanggal: " + s.Date + "."
	}
	if s.Kind == "profile" {
		if containsAny(lower, "tagline") {
			if text := labelled(s.Text, "Tagline"); text != "" {
				return text
			}
		}
		if containsAny(lower, "nama") {
			if text := labelled(s.Text, "Nama"); text != "" {
				return text
			}
		}
		if containsAny(lower, "mulai", "anniversary", "sejak kapan") && s.Date != "" {
			return "Mulai hubungan: " + s.Date + "."
		}
		return s.Text
	}
	if s.Kind == "album" && containsAny(lower, "nama", "judul") {
		return "Judul: " + s.Title + "."
	}
	// Place/title words identify the record; they do not prove a missing detail
	// such as a hotel's name or a car's color. Require a topic match as well.
	if s.Kind == "memory" {
		topics := []string{}
		for _, w := range terms(q) {
			if !matches(s.Title, w) && !matches(labelled(s.Text, "Lokasi"), w) {
				topics = append(topics, w)
			}
		}
		if len(topics) > 0 {
			supported := false
			for _, w := range topics {
				if matches(s.Text, w) {
					supported = true
					break
				}
			}
			if !supported {
				return ""
			}
		}
	}
	if containsAny(lower, "kota", "lokasi") {
		if text := labelled(s.Text, "Lokasi"); text != "" {
			return text
		}
	}
	if s.Kind == "album" || s.Kind == "letter" {
		return s.Text
	}
	keywords := terms(q)
	best := 0
	selected := []string{}
	for _, sentence := range sentences(s.Text) {
		score := 0
		for _, w := range keywords {
			if matches(sentence, w) {
				score++
			}
		}
		if score > best {
			best = score
			selected = []string{sentence}
		} else if score == best && score > 0 {
			selected = append(selected, sentence)
		}
	}
	if best > 0 {
		return strings.Join(selected, " ")
	}
	// Generic requests to tell a story can quote the actual record. A new missing
	// topic (e.g. hotel in an otherwise matching trip) must abstain instead.
	remaining := []string{}
	for _, w := range keywords {
		if !matches(s.Title, w) && !matches(s.Text, w) {
			remaining = append(remaining, w)
		}
	}
	if len(remaining) > 0 {
		return ""
	}
	return s.Text
}
func preparedInput(in Input) (Input, string) {
	if containsAny(strings.ToLower(in.Question), "draft", "draf") {
		return in, "draft"
	}
	conversation := isConversation(in.Question)
	if !conversation && len(in.History) > 0 {
		q := strings.ToLower(in.Question)
		// Continue a disclosure such as "Soalnya dia jarang membalas pesanku",
		// while allowing a new explicit factual question to return to retrieval.
		if !containsAny(q, "apa", "siapa", "kapan", "mana", "album", "surat", "tanggal", "kenangan", "tagline", "anniversary") {
			for i := len(in.History) - 1; i >= 0; i-- {
				if in.History[i].Role == "user" {
					conversation = isConversation(in.History[i].Content)
					break
				}
			}
		}
	}
	if conversation {
		in.Sources = nil
		in.Mode = "conversation"
		return in, "conversation"
	}
	selected := selectSources(in)
	_, _, dated := temporalRange(strings.ToLower(in.Question), in.Today)
	list := dated && containsAny(strings.ToLower(in.Question), "kenangan", "momen") && !containsAny(strings.ToLower(in.Question), "kapan", "tanggal berapa")
	out := []Source{}
	for _, s := range selected {
		text := excerpt(s, followupQuestion(in))
		if list {
			text = s.Title + " — " + s.Date + "."
		}
		if text != "" {
			s.Text = text
			out = append(out, s)
		}
	}
	in.Sources = out
	in.History = nil
	in.Mode = "facts"
	if len(out) == 0 {
		return in, "not_found"
	}
	return in, "facts"
}
func normalized(s string) string {
	return strings.Join(strings.FieldsFunc(strings.ToLower(s), func(r rune) bool { return !unicode.IsLetter(r) && !unicode.IsNumber(r) }), " ")
}
func coreExcerpt(s string) string {
	for _, label := range []string{"Tanggal:", "Nama:", "Judul:", "Lokasi:", "Tagline:", "Mulai hubungan:"} {
		if strings.HasPrefix(s, label) {
			return strings.TrimSpace(strings.TrimPrefix(s, label))
		}
	}
	return s
}
func supportedCopy(candidate string, sources []Source) bool {
	// Require complete selected evidence, not merely a word also present in it.
	for _, s := range sources {
		if normalized(candidate) == normalized(coreExcerpt(s.Text)) || normalized(candidate) == normalized(s.Text) {
			return len(sources) == 1
		}
	}
	return false
}
func evidenceAnswer(sources []Source) string {
	lines := []string{}
	for _, s := range sources {
		lines = append(lines, coreExcerpt(s.Text))
	}
	return strings.Join(lines, "\n\n")
}
func conversationOptions(context ...string) []string {
	latest := ""
	if len(context) > 0 {
		latest = strings.ToLower(context[0])
	}
	all := strings.ToLower(strings.Join(context, " "))
	options := []string{"Apa yang paling ingin kamu ceritakan sekarang?", "Bagaimana perasaanmu saat ini?", "Bagian mana yang paling berkesan buatmu hari ini?"}
	switch {
	case strings.HasPrefix(latest, "halo") || strings.HasPrefix(latest, "hai"):
		options = []string{"Bagaimana harimu?", "Apa yang ingin kamu ceritakan hari ini?"}
	case containsAny(latest, "capek", "lelah"):
		options = []string{"Bagian mana dari harimu yang paling melelahkan?", "Apa yang biasanya membantumu beristirahat?", "Bagaimana kamu ingin mengambil jeda saat ini?"}
	case containsAny(latest, "senang", "bahagia", "bersyukur") && !containsAny(latest, "tidak senang", "tidak bahagia"):
		options = []string{"Momen apa yang paling berarti buatmu hari ini?", "Apa hal kecil yang paling ingin kamu ingat dari hari ini?", "Bagaimana perasaanmu setelah mengalami momen itu?"}
	case containsAny(all, "didengar", "membalas", "pesanku", "komunikasi"):
		options = []string{"Apa yang ingin kamu sampaikan agar perasaanmu lebih dipahami?", "Bagaimana cara komunikasi yang terasa nyaman buatmu?", "Apa yang kamu harapkan dari komunikasi kalian?"}
	case containsAny(latest, "bertengkar", "bingung", "kesal"):
		options = []string{"Bagian mana dari situasi ini yang paling ingin kamu pahami?", "Apa yang terjadi sebelum situasi ini terasa sulit?", "Apa yang kamu butuhkan agar bisa bercerita dengan lebih nyaman?"}
	case containsAny(latest, "sedih", "berat", "kecewa", "kesepian"):
		options = []string{"Apa yang paling terasa berat buatmu saat ini?", "Bagian mana yang paling ingin kamu ceritakan?", "Apa yang biasanya membuatmu merasa sedikit lebih nyaman?"}
	}
	// Do not ask the same prepared question twice in recent conversation.
	fresh := []string{}
	for _, q := range options {
		seen := false
		for _, turn := range context[minimum(1, len(context)):] {
			if strings.Contains(turn, q) {
				seen = true
				break
			}
		}
		if !seen {
			fresh = append(fresh, q)
		}
	}
	if len(fresh) > 0 {
		return fresh
	}
	return options
}
func minimum(a, b int) int {
	if a < b {
		return a
	}
	return b
}
func conversationAnswer(candidate string, context ...string) (string, bool) {
	options := conversationOptions(context...)
	candidate = strings.TrimSpace(candidate)
	valid := false
	for _, q := range options {
		if candidate == q {
			valid = true
			break
		}
	}
	if !valid {
		candidate = options[0]
	}
	intro := "Terima kasih sudah bercerita. Aku mendengarkan. "
	if len(context) > 0 {
		q := strings.ToLower(context[0])
		if strings.HasPrefix(q, "halo") || strings.HasPrefix(q, "hai") {
			intro = "Hai, aku di sini untuk mendengarkan. "
		}
	}
	return intro + candidate, !valid
}
