import os
import sys
import wave

SAMPLE_RATE = 16_000
CHUNK_FRAMES = 300 * SAMPLE_RATE
HALF_SECOND = SAMPLE_RATE // 2


def read_chunk(source, np):
    start = source.tell()
    data = source.readframes(CHUNK_FRAMES)
    audio = np.frombuffer(data, dtype="<i2").astype(np.float32) / 32768

    if source.tell() < source.getnframes():
        # Split a long recording at a quiet point near the end of the chunk.
        tail = audio[-10 * HALF_SECOND :].reshape(10, HALF_SECOND)
        quiet = int(np.argmin(np.mean(tail * tail, axis=1)))
        split = len(audio) - (10 - quiet) * HALF_SECOND + HALF_SECOND // 2
        audio = audio[:split]
        source.setpos(start + split)
    return audio


def transcribe(input_path, output_path, max_seconds):
    with wave.open(input_path, "rb") as source:
        if source.getnframes() > max_seconds * SAMPLE_RATE:
            return 3
        if source.getnframes() == 0:
            return 4

        os.environ.setdefault("HF_HUB_DISABLE_TELEMETRY", "1")
        os.environ.setdefault("HF_HUB_DISABLE_PROGRESS_BARS", "1")

        import numpy as np
        from faster_whisper import WhisperModel

        model = WhisperModel(
            os.getenv("TRANSAR_MODEL") or "large-v3-turbo",
            device="cpu",
            compute_type="int8",
            cpu_threads=int(os.getenv("TRANSAR_CPU_THREADS", "4")),
        )
        language = None
        with open(output_path, "w", encoding="utf-8") as result:
            while source.tell() < source.getnframes():
                audio = read_chunk(source, np)
                if len(audio) == 0:
                    break
                segments, info = model.transcribe(audio, language=language, vad_filter=True)
                text = " ".join(segment.text.strip() for segment in segments).strip()
                if not text:
                    continue
                if language is not None:
                    result.write("\n\n")
                language = info.language
                result.write(text)

            if language is None:
                return 4
            result.write("\n")
        return 0


if __name__ == "__main__":
    raise SystemExit(transcribe(sys.argv[1], sys.argv[2], int(sys.argv[3])))
