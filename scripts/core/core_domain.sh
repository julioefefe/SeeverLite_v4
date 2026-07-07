#!/bin/bash
# ============================================================================
# Core Script: Domain Configuration
# SeederLinux Lite - Ingresso em Dominio AD
# ============================================================================
# This script joins the Linux station to an Active Directory domain using
# SSSD/realmd, configures sudoers for AD groups, and sets up PAM for
# automatic home directory creation.
#
# Placeholders (replaced by SeederLinux Lite at bundle generation time):
#   {{DOMINIO}}            - Full AD domain name (e.g., comara.intraer)
#   {{DOMINIO_NETBIOS}}    - NetBIOS domain name (e.g., COMARA)
#   {{DC_IP}}              - IP of the Domain Controller
#   {{DNS_INTERNET}}       - Fallback DNS for internet resolution
#   {{GRUPO_ADMIN_AD}}     - AD admin group for sudo
#   {{GRUPO_ADMIN_LINUX}}  - Local Linux admin group for sudo
#   {{GRUPO_DASTI}}        - DASTI group for passwordless sudo
# ============================================================================

set -e

echo "============================================================"
echo "CONFIGURANDO DOMÍNIO E AUTENTICAÇÃO"
echo "============================================================"

# Variaveis de dominio
DOMINIO="{{DOMINIO}}"
DOMINIO_NETBIOS="{{DOMINIO_NETBIOS}}"
DC_IP="{{DC_IP}}"
DNS_INTERNET="{{DNS_INTERNET}}"
GRUPO_ADMIN_AD="{{GRUPO_ADMIN_AD}}"
GRUPO_ADMIN_LINUX="{{GRUPO_ADMIN_LINUX}}"
GRUPO_DASTI="{{GRUPO_DASTI}}"

echo ">>> Domínio: $DOMINIO ($DOMINIO_NETBIOS)"
echo ">>> Controlador: $DC_IP"

# Verificar se o hostname esta correto
CURRENT_HOSTNAME=$(hostname)
echo ">>> Hostname atual: $CURRENT_HOSTNAME"

# Instalar pacotes necessarios
echo ">>> Instalando pacotes de autenticacao..."
sudo apt-get update -qq
sudo apt-get install -y -qq sssd sssd-ad adcli realmd krb5-user packagekit

# Configurar DNS para resolver o dominio
echo ">>> Configurando DNS para dominio..."
sudo cp /etc/resolv.conf /etc/resolv.conf.bak 2>/dev/null || true

# Preparar ingresso no dominio
echo ">>> Preparando ingresso no domínio..."
echo "Por favor, forneça as credenciais do administrador do domínio quando solicitado."

# Descobrir realm
echo ">>> Descobrindo realm..."
sudo realm discover "$DOMINIO" || echo "Aviso: Não foi possível descobrir o realm via DNS"

# Ingressar no dominio
echo ">>> Ingressando no dominio..."
sudo realm join "$DOMINIO" --user=admin || {
    echo "Tentando ingresso com usuario especifico..."
    sudo realm join "$DOMINIO" --user=Administrator
}

# Configurar SSSD
echo ">>> Configurando SSSD..."
sudo tee /etc/sssd/sssd.conf > /dev/null <<EOF
[sssd]
domains = $DOMINIO
services = nss, pam

[domain/$DOMINIO]
ad_domain = $DOMINIO
ad_server = $DC_IP
ad_hostname = $(hostname).$DOMINIO
krb5_realm = $(echo $DOMINIO | tr '[:lower:]' '[:upper:]')
realmd_tags = manages-system joined-with-adcli
cache_credentials = True
id_provider = ad
auth_provider = ad
chpass_provider = ad
access_provider = ad
ldap_id_mapping = True
use_fully_qualified_names = False
fallback_homedir = /home/%u@%d
simple_allow_groups = $GRUPO_ADMIN_AD, $GRUPO_ADMIN_LINUX, $GRUPO_DASTI
dyndns_update = True
dyndns_refresh_interval = 43200
dyndns_update_ptr = True
EOF

sudo chmod 600 /etc/sssd/sssd.conf
sudo systemctl enable sssd
sudo systemctl restart sssd

# Configurar sudoers para grupos AD
echo ">>> Configurando sudoers para grupos do domínio..."
sudo tee /etc/sudoers.d/domain_admins > /dev/null <<EOF
# Admins do dominio tem acesso sudo
%$GRUPO_ADMIN_AD ALL=(ALL) ALL
%$GRUPO_ADMIN_LINUX ALL=(ALL) ALL
%$GRUPO_DASTI ALL=(ALL) NOPASSWD: ALL

# Membros do dominio podem montar/desmontar
%${DOMINIO_NETBIOS}\\domain\ users ALL=/sbin/mount,/sbin/umount
EOF

sudo chmod 440 /etc/sudoers.d/domain_admins

# Configurar PAM para criar home automaticamente
echo ">>> Configurando PAM para criação automática de home..."
sudo sed -i '/^[^#]*pam_mkhomedir.so/s/^#//' /etc/pam.d/common-session
echo "session required pam_mkhomedir.so skel=/etc/skel/ umask=0077" | sudo tee -a /etc/pam.d/common-session

# Verificar conexao
echo ">>> Verificando conexao com dominio..."
id admin@${DOMINIO,,} 2>/dev/null || echo "Aviso: Não foi possível verificar o usuario admin"

echo ">>> Configuração de domínio concluída!"
echo "============================================================"
