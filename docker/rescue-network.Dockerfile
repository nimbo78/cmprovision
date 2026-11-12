FROM debian:trixie-slim
RUN apt-get update && apt-get install -y --no-install-recommends \
    python3 network-manager wpasupplicant dnsmasq-base nftables iproute2 iputils-ping \
    busybox dbus udev util-linux && rm -rf /var/lib/apt/lists/*
WORKDIR /work
COPY tools/ /work/tools/
COPY tests/network/ /work/tests/network/
COPY debian/ /work/debian/
ENV CMP_RESCUE_NETWORK_TEST=1
CMD ["python3", "-B", "-m", "unittest", "discover", "-s", "tests/network", "-v"]
