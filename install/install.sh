#!/bin/bash
# ============================================================================
# SeederLinux Lite - Installation Script
# Self-contained installer for Debian 12/13 or Ubuntu 22.04+
# ============================================================================

set -e
set -u

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
CYAN='\033[0;36m'
BOLD='\033[1m'
NC='\033[0m'

PROJECT_NAME="seederlinux-lite"
INSTALL_DIR="/var/www/${PROJECT_NAME}"
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(dirname "$SCRIPT_DIR")"

# Database defaults
DB_NAME="${DB_NAME:-seederlinux}"
DB_USER="${DB_USER:-seeder}"
DB_PASS="${DB_PASS:-seeder123}"

APACHE_USER="www-data"
APACHE_GROUP="www-data"
SERVER_NAME="${SERVER_NAME:-localhost}"

print_header() {
    echo -e "\n${CYAN}${BOLD}============================================================================${NC}"
    echo -e "${CYAN}${BOLD}$1${NC}"
    echo -e "${CYAN}${BOLD}============================================================================${NC}\n"
}

print_step()   { echo -e "${BLUE}[+]${NC} $1"; }
print_success() { echo -e "${GREEN}[OK]${NC} $1"; }
print_warning() { echo -e "${YELLOW}[!]${NC} $1"; }
print_error()   { echo -e "${RED}[X]${NC} $1"; }

check_root() {
    if [[ $EUID -ne 0 ]]; then
        print_error "Este script deve ser executado como root"
        print_step "Use: sudo $0"
        exit 1
    fi
}

detect_distro() {
    if [ -f /etc/debian_version ]; then
        DISTRO="debian"
        PHP_VER=$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;' 2>/dev/null || echo "8.2")
    elif [ -f /etc/lsb-release ] && grep -q "Ubuntu" /etc/lsb-release 2>/dev/null; then
        DISTRO="ubuntu"
        PHP_VER=$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;' 2>/dev/null || echo "8.1")
    else
        DISTRO="debian"
        PHP_VER="8.2"
    fi
    print_step "Distribuição: $DISTRO | PHP: $PHP_VER"
}

install_dependencies() {
    print_header "INSTALANDO DEPENDÊNCIAS DO SISTEMA"

    print_step "Atualizando lista de pacotes..."
    apt-get update -qq

    print_step "Instalando utilitários básicos..."
    apt-get install -y -qq curl wget git unzip ca-certificates apt-transport-https

    print_step "Instalando PostgreSQL..."
    apt-get install -y -qq postgresql postgresql-contrib

    print_step "Instalando Apache2..."
    apt-get install -y -qq apache2

    print_step "Instalando PHP e extensões..."
    apt-get install -y -qq \
        php \
        php-cli \
        php-pgsql \
        php-mbstring \
        php-xml \
        libapache2-mod-php

    print_step "Habilitando módulos do Apache..."
    a2enmod rewrite headers ssl 2>/dev/null || true

    print_success "Dependências instaladas"
}

setup_postgresql() {
    print_header "CONFIGURANDO POSTGRESQL"

    print_step "Iniciando serviço PostgreSQL..."
    systemctl start postgresql
    systemctl enable postgresql

    # Check if database exists
    if sudo -u postgres psql -lqt 2>/dev/null | cut -d \| -f 1 | grep -qw "${DB_NAME}"; then
        print_warning "Banco de dados '${DB_NAME}' já existe"
        read -p "Deseja recriar o banco? (s/N): " -n 1 -r
        echo
        if [[ $REPLY =~ ^[Ss]$ ]]; then
            print_step "Removendo banco existente..."
            sudo -u postgres dropdb --if-exists "${DB_NAME}" 2>/dev/null || true
            sudo -u postgres dropuser --if-exists "${DB_USER}" 2>/dev/null || true
        else
            print_step "Mantendo banco existente"
            return 0
        fi
    fi

    print_step "Criando usuário: ${DB_USER}"
    sudo -u postgres psql -c "CREATE USER ${DB_USER} WITH PASSWORD '${DB_PASS}';" 2>/dev/null || true

    print_step "Criando banco: ${DB_NAME}"
    sudo -u postgres psql -c "CREATE DATABASE ${DB_NAME} OWNER ${DB_USER};" 2>/dev/null || true

    print_step "Concedendo privilégios..."
    sudo -u postgres psql -c "GRANT ALL PRIVILEGES ON DATABASE ${DB_NAME} TO ${DB_USER};" 2>/dev/null || true

    # PostgreSQL 15+ needs schema permissions
    sudo -u postgres psql -d "${DB_NAME}" -c "GRANT ALL ON SCHEMA public TO ${DB_USER};" 2>/dev/null || true

    print_success "PostgreSQL configurado"
}

apply_database_schema() {
    print_header "APLICANDO SCHEMA DO BANCO DE DADOS"

    SCHEMA_FILE="${SCRIPT_DIR}/schema_completo.sql"

    if [ ! -f "$SCHEMA_FILE" ]; then
        print_error "Schema não encontrado: $SCHEMA_FILE"
        exit 1
    fi

    print_step "Aplicando schema completo..."
    PGPASSWORD="${DB_PASS}" psql -h localhost -U "${DB_USER}" -d "${DB_NAME}" -f "$SCHEMA_FILE" 2>&1 | grep -v "already exists" || true

    print_success "Schema aplicado"
}

setup_project_files() {
    print_header "CONFIGURANDO ARQUIVOS DO PROJETO"

    print_step "Criando diretório: ${INSTALL_DIR}"
    mkdir -p "${INSTALL_DIR}"

    print_step "Copiando arquivos do projeto..."
    cp -r "${PROJECT_ROOT}"/* "${INSTALL_DIR}/"

    print_step "Criando diretórios de armazenamento..."
    mkdir -p "${INSTALL_DIR}/storage/logs"
    mkdir -p "${INSTALL_DIR}/downloads"

    print_step "Criando arquivo .env..."
    cat > "${INSTALL_DIR}/.env" <<EOF
DB_HOST=localhost
DB_PORT=5432
DB_NAME=${DB_NAME}
DB_USER=${DB_USER}
DB_PASS=${DB_PASS}

APP_NAME=SeederLinux Lite
APP_ENV=production
APP_DEBUG=false
EOF

    print_success "Arquivos configurados"
}

setup_permissions() {
    print_header "CONFIGURANDO PERMISSÕES"

    print_step "Ajustando proprietário..."
    chown -R ${APACHE_USER}:${APACHE_GROUP} "${INSTALL_DIR}"

    print_step "Configurando permissões de diretórios..."
    find "${INSTALL_DIR}" -type d -exec chmod 755 {} \;

    print_step "Configurando permissões de arquivos..."
    find "${INSTALL_DIR}" -type f -exec chmod 644 {} \;

    print_step "Configurando permissões especiais..."
    chmod -R 775 "${INSTALL_DIR}/storage"
    chmod -R 775 "${INSTALL_DIR}/downloads"
    chmod 600 "${INSTALL_DIR}/.env"

    # Make scripts executable
    chmod +x "${INSTALL_DIR}/downloads/"*.py 2>/dev/null || true
    chmod +x "${INSTALL_DIR}/scripts/"*.sh 2>/dev/null || true
    chmod +x "${INSTALL_DIR}/install/"*.sh 2>/dev/null || true

    print_success "Permissões configuradas"
}

configure_apache() {
    print_header "CONFIGURANDO APACHE"

    print_step "Criando VirtualHost..."

    cat > "/etc/apache2/sites-available/${PROJECT_NAME}.conf" <<EOF
<VirtualHost *:80>
    ServerName ${SERVER_NAME}

    DocumentRoot ${INSTALL_DIR}

    <Directory ${INSTALL_DIR}>
        Options -Indexes +FollowSymLinks
        AllowOverride All
        Require all granted
    </Directory>

    <Directory ${INSTALL_DIR}/storage>
        Require all denied
    </Directory>

    <Directory ${INSTALL_DIR}/lib>
        Require all denied
    </Directory>

    <Directory ${INSTALL_DIR}/includes>
        Require all denied
    </Directory>

    ErrorLog \${APACHE_LOG_DIR}/${PROJECT_NAME}_error.log
    CustomLog \${APACHE_LOG_DIR}/${PROJECT_NAME}_access.log combined
</VirtualHost>
EOF

    print_step "Desabilitando site padrão..."
    a2dissite 000-default.conf 2>/dev/null || true

    print_step "Habilitando site ${PROJECT_NAME}..."
    a2ensite "${PROJECT_NAME}.conf"

    print_step "Testando configuração do Apache..."
    if apache2ctl configtest 2>&1 | grep -q "Syntax OK"; then
        print_success "Configuração do Apache OK"
    else
        print_warning "Erro na configuração do Apache"
        apache2ctl configtest
    fi

    print_step "Reiniciando Apache..."
    systemctl restart apache2
    systemctl enable apache2

    print_success "Apache configurado"
}

show_summary() {
    print_header "INSTALAÇÃO CONCLUÍDA"

    echo -e "${GREEN}"
    echo "╔══════════════════════════════════════════════════════════════════╗"
    echo "║           SEEDERLINUX LITE - INSTALAÇÃO COMPLETA               ║"
    echo "╚══════════════════════════════════════════════════════════════════╝"
    echo -e "${NC}"

    echo -e "\n${BOLD}Acesso Web:${NC}"
    echo -e "  URL:   http://${SERVER_NAME}/"
    echo -e "  Login: http://${SERVER_NAME}/login.html"
    echo -e "  Admin: http://${SERVER_NAME}/admin.html"

    echo -e "\n${BOLD}Credenciais Padrão:${NC}"
    echo -e "  Usuário: ${YELLOW}admin${NC}"
    echo -e "  Senha:   ${YELLOW}admin123${NC}"
    echo -e "  ${RED}⚠ ALTERE A SENHA APÓS O PRIMEIRO LOGIN!${NC}"

    echo -e "\n${BOLD}Banco de Dados:${NC}"
    echo -e "  Database: ${DB_NAME}"
    echo -e "  User: ${DB_USER}"
    echo -e "  Pass: ${DB_PASS}"
    echo -e "  Host: localhost:5432"

    echo -e "\n${BOLD}Arquivos:${NC}"
    echo -e "  Diretório: ${INSTALL_DIR}"
    echo -e "  Schema: ${INSTALL_DIR}/install/schema_completo.sql"
    echo -e "  Config: ${INSTALL_DIR}/.env"

    echo -e "\n${BOLD}Logs:${NC}"
    echo -e "  Apache: /var/log/apache2/${PROJECT_NAME}_error.log"
    echo -e "  Sistema: ${INSTALL_DIR}/storage/logs/"

    echo -e "\n${GREEN}Instalação concluída com sucesso!${NC}\n"
}

main() {
    clear
    echo -e "${CYAN}${BOLD}"
    echo "╔══════════════════════════════════════════════════════════════════╗"
    echo "║       SEEDEALINUX LITE - INSTALADOR v1.0.0                     ║"
    echo "╚══════════════════════════════════════════════════════════════════╝"
    echo -e "${NC}\n"

    check_root
    detect_distro

    echo -e "${YELLOW}Este script irá:${NC}"
    echo "  • Instalar Apache2, PHP 8+, PostgreSQL"
    echo "  • Criar banco e usuário"
    echo "  • Aplicar schema completo (tabelas, índices, dados iniciais)"
    echo "  • Configurar VirtualHost"
    echo "  • Copiar arquivos para ${INSTALL_DIR}"
    echo ""
    read -p "Deseja continuar? (s/N): " -n 1 -r
    echo
    if [[ ! $REPLY =~ ^[Ss]$ ]]; then
        echo "Instalação cancelada."
        exit 0
    fi

    install_dependencies
    setup_postgresql
    apply_database_schema
    setup_project_files
    setup_permissions
    configure_apache
    show_summary
}

main "$@"
