# Build the CPU runtime locally. No Cloud Build or GPU dependencies.
FROM debian:bookworm-slim AS llama
ARG LLAMA_COMMIT
RUN test -n "$LLAMA_COMMIT" && apt-get update && apt-get install -y --no-install-recommends git ca-certificates cmake g++ make && rm -rf /var/lib/apt/lists/*
RUN git init /src && cd /src && git remote add origin https://github.com/ggml-org/llama.cpp.git && git fetch --depth=1 origin "$LLAMA_COMMIT" && git checkout FETCH_HEAD
RUN cmake -S /src -B /build -DCMAKE_BUILD_TYPE=Release -DBUILD_SHARED_LIBS=OFF -DGGML_NATIVE=OFF -DGGML_OPENMP=OFF -DLLAMA_CURL=OFF -DLLAMA_OPENSSL=OFF && cmake --build /build --target llama-server -j2
FROM golang:1.26-bookworm AS gateway
WORKDIR /src
COPY go.mod main.go grounding.go ./
RUN CGO_ENABLED=0 go build -trimpath -ldflags="-s -w" -o /gateway .
FROM debian:bookworm-slim
RUN apt-get update && apt-get install -y --no-install-recommends libstdc++6 && rm -rf /var/lib/apt/lists/*
# Keep this immutable layer identical between updates to avoid duplicate model storage.
COPY model/qwen.gguf /model/qwen.gguf
COPY --from=llama /build/bin/llama-server /usr/local/bin/llama-server
COPY --from=gateway /gateway /gateway
USER 65534:65534
ENV PORT=8080 MODEL_PATH=/model/qwen.gguf
EXPOSE 8080
ENTRYPOINT ["/gateway"]
