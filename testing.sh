#!/bin/bash
# === KERNEL RECON — ROOT PATH MAPPING ===

echo "========== KERNEL & OS =========="
uname -a
cat /etc/os-release 2>/dev/null
cat /etc/redhat-release 2>/dev/null
cat /proc/version

echo "========== CURRENT USER =========="
id
whoami
echo "Groups: $(groups)"

echo "========== SUDO RIGHTS =========="
sudo -l 2>/dev/null
echo "[sudo version]"
sudo --version 2>/dev/null | head -1

echo "========== SUID BINARIES =========="
find / -perm -4000 -type f 2>/dev/null | while read bin; do
    echo "[SUID] $bin"
    ls -la "$bin" 2>/dev/null
done

echo "========== CAPABILITIES =========="
getcap -r / 2>/dev/null

echo "========== WRITABLE PATHS =========="
find / -writable -type d 2>/dev/null | grep -v -E "(proc|sys|dev|run)" | head -30
echo "[Writable /etc/ files]"
find /etc -writable -type f 2>/dev/null

echo "========== CRON JOBS =========="
cat /etc/crontab 2>/dev/null
ls -la /etc/cron.d/ 2>/dev/null
ls -la /etc/cron.daily/ 2>/dev/null
ls -la /etc/cron.hourly/ 2>/dev/null
crontab -l 2>/dev/null
echo "[World-writable cron dirs]"
find /etc/cron* -writable -type d 2>/dev/null

echo "========== RUNNING SERVICES (ROOT) =========="
ps aux | grep "^root" | grep -v "\[" 

echo "========== LISTENING PORTS =========="
ss -tlnp 2>/dev/null
netstat -tlnp 2>/dev/null

echo "========== DOCKER / LXC =========="
docker ps 2>/dev/null
ls -la /var/run/docker.sock 2>/dev/null
id | grep docker 2>/dev/null

echo "========== NFS SHARES =========="
showmount -e 127.0.0.1 2>/dev/null
cat /etc/exports 2>/dev/null
mount | grep nfs

echo "========== INTERESTING FILES =========="
ls -la /etc/shadow 2>/dev/null
ls -la /etc/passwd 2>/dev/null
cat /etc/passwd 2>/dev/null | grep -v "nologin\|false"

echo "========== KERNEL EXPLOIT SURFACE =========="
cat /proc/cpuinfo | grep -E "model name|bugs"
echo "[Kernel modules loaded]"
lsmod 2>/dev/null | head -20
echo "[Sysctl — kernel.unprivileged_userns_clone]"
sysctl kernel.unprivileged_userns_clone 2>/dev/null
echo "[Sysctl — user.max_user_namespaces]"
sysctl user.max_user_namespaces 2>/dev/null
echo "[PTRACE scope]"
sysctl kernel.yama.ptrace_scope 2>/dev/null
echo "[AppArmor?]"
aa-status 2>/dev/null || echo "Not present"
echo "[SELinux?]"
sestatus 2>/dev/null || getenforce 2>/dev/null

echo "========== PATH HIJACK =========="
echo $PATH
find / -writable -type d 2>/dev/null | while read dir; do
    echo "$PATH" | tr ':' '\n' | grep -q "^$dir$" && echo "[WRITABLE IN PATH] $dir"
done

echo "========== RECENTLY MODIFIED =========="
find /etc -type f -mmin -1440 2>/dev/null | grep -v proc

echo "========== ENV VARS =========="
env 2>/dev/null | grep -iE "pass|secret|token|key|auth"

echo "========== DISK SPACE (tmp abuse) =========="
df -h /tmp /dev/shm /var/tmp 2>/dev/null

echo ""
echo "[=== RECON COMPLETE ===]"
echo "Dump the output back to me and we'll find the way in, babe."
