# Debian 13 with systemd as PID 1, close to a fresh VM. Used by CI only.
FROM debian:trixie
ENV container=docker DEBIAN_FRONTEND=noninteractive
RUN apt-get update -q \
 && apt-get install -y -q --no-install-recommends systemd systemd-sysv dbus ca-certificates curl \
      git openssh-server procps iproute2 kmod \
 && rm -f /etc/machine-id && systemd-machine-id-setup \
 && systemctl mask systemd-firstboot.service getty@tty1.service \
 && rm -rf /var/lib/apt/lists/*
STOPSIGNAL SIGRTMIN+3
CMD ["/lib/systemd/systemd"]
