# Extend the supported PHP bench; production packaging uses a separate clean archive.
FROM cmprovision-test
USER root
RUN apt-get update && apt-get install -y --no-install-recommends \
        python3-pil python3-pytest fonts-dejavu-core \
    && rm -rf /var/lib/apt/lists/*
ENTRYPOINT ["bash"]
