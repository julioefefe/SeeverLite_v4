-- ============================================================================
-- SeederLinux Lite - Database Schema
-- PostgreSQL 16+
-- ============================================================================

-- Drop existing tables if they exist (for reinstall)
DROP TABLE IF EXISTS script_executions CASCADE;
DROP TABLE IF EXISTS script_variables CASCADE;
DROP TABLE IF EXISTS organization_variables CASCADE;
DROP TABLE IF EXISTS scripts CASCADE;
DROP TABLE IF EXISTS variable_definitions CASCADE;
DROP TABLE IF EXISTS organizations CASCADE;
DROP TABLE IF EXISTS users CASCADE;

-- ============================================================================
-- Table: users
-- Purpose: Store admin users for the panel
-- ============================================================================
CREATE TABLE users (
    id SERIAL PRIMARY KEY,
    username VARCHAR(50) UNIQUE NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    email VARCHAR(100),
    full_name VARCHAR(150),
    role VARCHAR(20) DEFAULT 'admin',
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Create default admin user (password: admin123)
-- Password hash generated with PASSWORD_BCRYPT (cost=12)
INSERT INTO users (username, password_hash, email, full_name, role)
VALUES ('admin', '$2y$12$aclfbpmKYX0DoMcu8EmQeO1xyziOBv9/WjuWR6y3/ovgF74QTaLhC', 'admin@seeder.local', 'Administrator', 'admin');

-- ============================================================================
-- Table: organizations
-- Purpose: Store military organizations (OMs)
-- ============================================================================
CREATE TABLE organizations (
    id SERIAL PRIMARY KEY,
    name VARCHAR(200) NOT NULL,
    acronym VARCHAR(20) UNIQUE NOT NULL,
    domain VARCHAR(100),
    description TEXT,
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Insert sample organization
INSERT INTO organizations (name, acronym, domain, description)
VALUES ('Comando da Comara', 'COMARA', 'comara.intraer', 'Comando do Comando da Aeronáutica de Brasília');

-- ============================================================================
-- Table: variable_definitions
-- Purpose: Define all available placeholders for scripts
-- ============================================================================
CREATE TABLE variable_definitions (
    id SERIAL PRIMARY KEY,
    name VARCHAR(50) UNIQUE NOT NULL,
    placeholder VARCHAR(50) UNIQUE NOT NULL,
    description TEXT,
    default_value TEXT,
    category VARCHAR(50) DEFAULT 'general',
    is_required BOOLEAN DEFAULT TRUE,
    display_order INTEGER DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Insert standard variable definitions
INSERT INTO variable_definitions (name, placeholder, description, default_value, category, is_required, display_order) VALUES
-- Domain Configuration
('DOMINIO', '{{DOMINIO}}', 'Domínio AD completo', 'comara.intraer', 'dominio', TRUE, 1),
('DOMINIO_NETBIOS', '{{DOMINIO_NETBIOS}}', 'Nome NetBIOS do domínio', 'COMARA', 'dominio', TRUE, 2),
('DC_IP', '{{DC_IP}}', 'IP do Controlador de Domínio', '10.108.64.51', 'dominio', TRUE, 3),
('DNS_INTERNET', '{{DNS_INTERNET}}', 'DNS para internet (fallback)', '10.108.64.27', 'rede', TRUE, 4),

-- Repository URLs
('BASE_URL', '{{BASE_URL}}', 'URL base do repositório de scripts', 'https://softwarelivre.comara.intraer', 'rede', TRUE, 5),

-- OCS Inventory Configuration
('OCS_SERVER', '{{OCS_SERVER}}', 'Servidor OCS Inventory', 'http://ocs.comara.intraer/ocsinventory', 'inventario', TRUE, 6),
('OCS_TAG', '{{OCS_TAG}}', 'Tag OCS da organização', 'GAPBE-COMARA', 'inventario', TRUE, 7),

-- Print Server
('PRINT_SERVER', '{{PRINT_SERVER}}', 'Servidor de impressão', '10.108.64.20', 'rede', FALSE, 8),

-- Proxy Configuration
('PROXY_HTTP', '{{PROXY_HTTP}}', 'Proxy HTTP corporativo', '10.108.88.4', 'proxy', FALSE, 9),
('PROXY_PORTA', '{{PROXY_PORTA}}', 'Porta do proxy', '8080', 'proxy', FALSE, 10),
('PROXY_URL', '{{PROXY_URL}}', 'URL completa do proxy', 'http://proxy.comara.intraer:8080', 'proxy', FALSE, 11),

-- Browser Configuration
('HOMEPAGE', '{{HOMEPAGE}}', 'Página inicial do portal', 'www.comara.intraer', 'navegador', FALSE, 12),

-- Admin Groups
('GRUPO_ADMIN_AD', '{{GRUPO_ADMIN_AD}}', 'Grupo admin no AD para sudo', 'Dominio\ Admins', 'seguranca', TRUE, 13),
('GRUPO_ADMIN_LINUX', '{{GRUPO_ADMIN_LINUX}}', 'Grupo local para sudo', 'linux-admins', 'seguranca', TRUE, 14),
('GRUPO_DASTI', '{{GRUPO_DASTI}}', 'Grupo DASTI para sudo', '_DASTI', 'seguranca', FALSE, 15),

-- Branding
('WALLPAPER_URL', '{{WALLPAPER_URL}}', 'URL do wallpaper da OM', 'https://softwarelivre.comara.intraer/wallpapers/comara.jpg', 'branding', FALSE, 16),
('LOGO_URL', '{{LOGO_URL}}', 'URL do logo da OM', 'https://softwarelivre.comara.intraer/logos/comara.png', 'branding', FALSE, 17);

-- ============================================================================
-- Table: organization_variables
-- Purpose: Store variable values for each organization
-- ============================================================================
CREATE TABLE organization_variables (
    id SERIAL PRIMARY KEY,
    organization_id INTEGER NOT NULL REFERENCES organizations(id) ON DELETE CASCADE,
    variable_id INTEGER NOT NULL REFERENCES variable_definitions(id) ON DELETE CASCADE,
    value TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(organization_id, variable_id)
);

-- Insert default values for COMARA organization
INSERT INTO organization_variables (organization_id, variable_id, value)
SELECT 1, id, default_value FROM variable_definitions;

-- ============================================================================
-- Table: scripts
-- Purpose: Store provisioning scripts with placeholders
-- ============================================================================
CREATE TABLE scripts (
    id SERIAL PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    filename VARCHAR(100) UNIQUE NOT NULL,
    description TEXT,
    content TEXT NOT NULL,
    is_core BOOLEAN DEFAULT FALSE,
    execution_order INTEGER DEFAULT 0,
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Insert core scripts
INSERT INTO scripts (name, filename, description, content, is_core, execution_order) VALUES
-- Core Script 1: Network Configuration
('Configuração de Rede', 'core_network.sh',
'Gerencia configurações de rede, incluindo proxy, página inicial do navegador e servidor de impressão',
'#!/bin/bash
# ============================================================================
# Core Script: Network Configuration
# SeederLinux Lite - Provisionamento de Rede
# ============================================================================

set -e

echo "============================================================"
echo "CONFIGURANDO REDE E PROXY"
echo "============================================================"

# Configurando variaveis
PROXY_HTTP="{{PROXY_HTTP}}"
PROXY_PORTA="{{PROXY_PORTA}}"
HOMEPAGE="{{HOMEPAGE}}"
PRINT_SERVER="{{PRINT_SERVER}}"
DNS_INTERNET="{{DNS_INTERNET}}"

# Configurar DNS
echo ">>> Configurando DNS..."
if [ -f /etc/resolv.conf ]; then
    sudo cp /etc/resolv.conf /etc/resolv.conf.bak
fi

# Configurar proxy do sistema (se aplicavel)
if [ -n "$PROXY_HTTP" ] && [ "$PROXY_HTTP" != " " ]; then
    echo ">>> Configurando proxy HTTP: $PROXY_HTTP:$PROXY_PORTA"

    # Exportar variaveis de ambiente
    export http_proxy="http://$PROXY_HTTP:$PROXY_PORTA"
    export https_proxy="http://$PROXY_HTTP:$PROXY_PORTA"
    export HTTP_PROXY="http://$PROXY_HTTP:$PROXY_PORTA"
    export HTTPS_PROXY="http://$PROXY_HTTP:$PROXY_PORTA"
    export no_proxy="localhost,127.0.0.1,{{DOMINIO}}"

    # Adicionar ao /etc/environment
    echo "http_proxy=\"http://$PROXY_HTTP:$PROXY_PORTA\"" | sudo tee -a /etc/environment
    echo "https_proxy=\"http://$PROXY_HTTP:$PROXY_PORTA\"" | sudo tee -a /etc/environment
    echo "no_proxy=\"localhost,127.0.0.1,{{DOMINIO}}\"" | sudo tee -a /etc/environment
fi

# Configurar pagina inicial do Firefox
echo ">>> Configurando página inicial do navegador..."
if [ -d /usr/lib/firefox ]; then
    # Criar configuracao de autoconfig para Firefox
    sudo tee /usr/lib/firefox/defaults/pref/autoconfig.js > /dev/null <<EOF
pref("general.config.filename", "mozilla.cfg");
pref("general.config.obscure_value", 0);
EOF

    sudo tee /usr/lib/firefox/mozilla.cfg > /dev/null <<EOF
//
lockPref("browser.startup.homepage", "http://$HOMEPAGE");
lockPref("startup.homepage_welcome_url", "http://$HOMEPAGE");
lockPref("browser.startup.page", 1);
EOF
fi

# Configurar impressora padrao (se CUPS instalado)
if command -v lpadmin &> /dev/null; then
    echo ">>> Configurando servidor de impressão..."
    if [ -n "$PRINT_SERVER" ] && [ "$PRINT_SERVER" != " " ]; then
        sudo lpadmin -p ImpressoraPadrao -E -v ipp://$PRINT_SERVER:631/printers/ImpressoraPadrao -m everywhere
    fi
fi

echo ">>> Configuração de rede concluída!"
echo "============================================================"',
TRUE, 1),

-- Core Script 2: Domain Join
('Ingresso em Domínio AD', 'core_domain.sh',
'Responsável pelo ingresso da estação no Active Directory (SSSD/Winbind) e configuração de grupos de sudoers',
'#!/bin/bash
# ============================================================================
# Core Script: Domain Configuration
# SeederLinux Lite - Ingresso em Dominio AD
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
# Backup do resolv.conf original
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
echo "============================================================"',
TRUE, 2),

-- Core Script 3: Inventory Agent
('Agente de Inventário OCS', 'core_inventory.sh',
'Configura o agente OCS Inventory para coleta de informações da estação',
'#!/bin/bash
# ============================================================================
# Core Script: OCS Inventory Agent Configuration
# SeederLinux Lite - Agente de Inventário
# ============================================================================

set -e

echo "============================================================"
echo "CONFIGURANDO AGENTE OCS INVENTORY"
echo "============================================================"

OCS_SERVER="{{OCS_SERVER}}"
OCS_TAG="{{OCS_TAG}}"
DOMINIO="{{DOMINIO}}"

echo ">>> Servidor OCS: $OCS_SERVER"
echo ">>> Tag: $OCS_TAG"

# Instalar dependencias
echo ">>> Instalando dependencias..."
sudo apt-get update -qq
sudo apt-get install -y -qq ocsinventory-agent

# Criar diretorio de configuracao
sudo mkdir -p /etc/ocsinventory-agent

# Criar configuracao do agente
echo ">>> Criando configuração do agente..."
sudo tee /etc/ocsinventory-agent/ocsinventory-agent.cfg > /dev/null <<EOF
server=$OCS_SERVER
tag=$OCS_TAG
ca=/etc/ssl/certs/ca-certificates.crt
basepackage=none
debug=0
EOF

# Criar script de execucao periodica
sudo tee /etc/cron.daily/ocsinventory-agent > /dev/null <<'EOF'
#!/bin/bash
/usr/bin/ocsinventory-agent --force --nosoftware --tag="$OCS_TAG" --server="$OCS_SERVER"
EOF

sudo chmod 755 /etc/cron.daily/ocsinventory-agent

# Executar primeira inventariacao
echo ">>> Executando primeira inventariação..."
sudo /usr/bin/ocsinventory-agent --force --tag="$OCS_TAG" --server="$OCS_SERVER" || {
    echo "Aviso: Primeira inventariação pode ter falhado. Verifique a conexao com o servidor."
}

echo ">>> Agente OCS Inventory configurado com sucesso!"
echo "============================================================"',
TRUE, 3),

-- Core Script 4: Branding
('Branding e Identidade Visual', 'core_branding.sh',
'Aplica configurações de identidade visual, como wallpaper e tema',
'#!/bin/bash
# ============================================================================
# Core Script: Branding Configuration
# SeederLinux Lite - Identidade Visual
# ============================================================================

set -e

echo "============================================================"
echo "CONFIGURANDO BRANDING E IDENTIDADE VISUAL"
echo "============================================================"

OM_ACRONYM="{{OM_ACRONYM}}"
WALLPAPER_URL="{{WALLPAPER_URL}}"
LOGO_URL="{{LOGO_URL}}"
PROXY_HTTP="{{PROXY_HTTP}}"
PROXY_PORTA="{{PROXY_PORTA}}"

echo ">>> OM: $OM_ACRONYM"

# Criar diretorios de branding
sudo mkdir -p /usr/share/backgrounds/seederlinux
sudo mkdir -p /usr/share/pixmaps/seederlinux

# Baixar wallpaper (se URL fornecida)
if [ -n "$WALLPAPER_URL" ] && [ "$WALLPAPER_URL" != " " ]; then
    echo ">>> Baixando wallpaper..."

    # Configurar proxy se necessario
    if [ -n "$PROXY_HTTP" ] && [ "$PROXY_HTTP" != " " ]; then
        export http_proxy="http://$PROXY_HTTP:$PROXY_PORTA"
        export https_proxy="http://$PROXY_HTTP:$PROXY_PORTA"
    fi

    wget -q -O /tmp/wallpaper.jpg "$WALLPAPER_URL" 2>/dev/null || {
        echo "Aviso: Não foi possível baixar o wallpaper. Usando padrão."
        # Criar wallpaper padrao com OM
        convert -size 1920x1080 xc:#1e3a5f \
            -pointsize 120 -fill white -gravity center \
            -annotate 0 "$OM_ACRONYM" /tmp/wallpaper.jpg 2>/dev/null || {
            # Se Imagemagick nao estiver disponivel, criar arquivo vazio
            sudo dd if=/dev/zero of=/tmp/wallpaper.jpg bs=1M count=1 2>/dev/null || true
        }
    }

    sudo cp /tmp/wallpaper.jpg /usr/share/backgrounds/seederlinux/wallpaper.jpg
    sudo ln -sf /usr/share/backgrounds/seederlinux/wallpaper.jpg /usr/share/backgrounds/default.jpg
fi

# Baixar logo (se URL fornecida)
if [ -n "$LOGO_URL" ] && [ "$LOGO_URL" != " " ]; then
    echo ">>> Baixando logo..."
    wget -q -O /tmp/logo.png "$LOGO_URL" 2>/dev/null || true
    if [ -f /tmp/logo.png ]; then
        sudo cp /tmp/logo.png /usr/share/pixmaps/seederlinux/logo.png
    fi
fi

# Configurar wallpaper padrao para LightDM (Linux Lite)
if [ -f /etc/lightdm/lightdm-gtk-greeter.conf ]; then
    echo ">>> Configurando LightDM..."
    sudo sed -i "s|^background=.*|background=/usr/share/backgrounds/seederlinux/wallpaper.jpg|" /etc/lightdm/lightdm-gtk-greeter.conf || true
fi

# Configurar wallpaper para XFCE4
if command -v xfconf-query &> /dev/null; then
    echo ">>> Configurando wallpaper XFCE4..."
    xfconf-query -c xfce4-desktop -p /backdrop/screen0/monitor0/workspace0/last-image \
        -s /usr/share/backgrounds/seederlinux/wallpaper.jpg 2>/dev/null || true
fi

# Criar arquivo de identidade do sistema
sudo tee /etc/seederlinux-identity > /dev/null <<EOF
# SeederLinux Lite - Sistema Provisionado
# Organizacao: $OM_ACRONYM
# Data: $(date "+%%Y-%%m-%%d %%H:%%M:%%S")
# Hostname: $(hostname)
EOF

echo ">>> Branding configurado com sucesso!"
echo "============================================================"',
TRUE, 4);

-- ============================================================================
-- Table: script_executions
-- Purpose: Log script executions for auditing
-- ============================================================================
CREATE TABLE script_executions (
    id SERIAL PRIMARY KEY,
    organization_id INTEGER REFERENCES organizations(id) ON DELETE SET NULL,
    script_filename VARCHAR(100),
    executed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    execution_ip VARCHAR(45),
    status VARCHAR(20) DEFAULT 'pending',
    output TEXT,
    agent_version VARCHAR(20)
);

-- ============================================================================
-- Table: activity_log
-- Purpose: Complete audit trail of all actions in the system
-- ============================================================================
CREATE TABLE activity_log (
    id SERIAL PRIMARY KEY,
    user_id INTEGER REFERENCES users(id) ON DELETE SET NULL,
    action VARCHAR(50) NOT NULL,
    target VARCHAR(100),
    target_id INTEGER,
    details TEXT,
    organization_id INTEGER REFERENCES organizations(id) ON DELETE SET NULL,
    ip_address VARCHAR(45),
    user_agent TEXT,
    session_id VARCHAR(64),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Create index for activity log searches
CREATE INDEX idx_activity_user ON activity_log(user_id);
CREATE INDEX idx_activity_action ON activity_log(action);
CREATE INDEX idx_activity_target ON activity_log(target, target_id);
CREATE INDEX idx_activity_date ON activity_log(created_at DESC);
CREATE INDEX idx_activity_org ON activity_log(organization_id);

-- ============================================================================
-- Table: system_settings
-- Purpose: Store global application settings
-- ============================================================================
CREATE TABLE system_settings (
    id SERIAL PRIMARY KEY,
    key VARCHAR(100) UNIQUE NOT NULL,
    value TEXT,
    value_type VARCHAR(20) DEFAULT 'string',
    description TEXT,
    is_public BOOLEAN DEFAULT FALSE,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_by INTEGER REFERENCES users(id) ON DELETE SET NULL
);

-- Insert default settings
INSERT INTO system_settings (key, value, value_type, description, is_public) VALUES
('app_name', 'SeederLinux Lite', 'string', 'Nome da aplicação', TRUE),
('app_version', '1.0.0', 'string', 'Versão do sistema', TRUE),
('require_https', 'false', 'boolean', 'Requer HTTPS para acesso', FALSE),
('max_login_attempts', '5', 'integer', 'Máximo de tentativas de login', FALSE),
('login_lockout_minutes', '15', 'integer', 'Minutos de bloqueio após tentativas', FALSE),
('session_timeout', '86400', 'integer', 'Timeout de sessão em segundos', FALSE),
('bundle_retention_days', '30', 'integer', 'Dias para reter bundles gerados', FALSE),
('max_bundle_downloads', '100', 'integer', 'Máximo de downloads por hora por OM', FALSE),
('enable_activity_log', 'true', 'boolean', 'Habilitar log de atividades', FALSE),
('default_timezone', 'America/Sao_Paulo', 'string', 'Fuso horário padrão', TRUE);

-- ============================================================================
-- Create indexes for performance
-- ============================================================================
CREATE INDEX idx_org_vars_org ON organization_variables(organization_id);
CREATE INDEX idx_org_vars_var ON organization_variables(variable_id);
CREATE INDEX idx_scripts_filename ON scripts(filename);
CREATE INDEX idx_scripts_core ON scripts(is_core, execution_order);
CREATE INDEX idx_exec_org ON script_executions(organization_id);
CREATE INDEX idx_exec_date ON script_executions(executed_at);
CREATE INDEX idx_exec_status ON script_executions(status);

-- ============================================================================
-- Create views for easier querying
-- ============================================================================

-- View: Organization full variables
CREATE VIEW v_organization_variables AS
SELECT
    o.id AS org_id,
    o.acronym AS org_acronym,
    o.name AS org_name,
    vd.id AS var_id,
    vd.name AS var_name,
    vd.placeholder,
    vd.description,
    vd.category,
    vd.default_value,
    COALESCE(ov.value, vd.default_value) AS current_value
FROM organizations o
CROSS JOIN variable_definitions vd
LEFT JOIN organization_variables ov ON ov.organization_id = o.id AND ov.variable_id = vd.id
WHERE o.is_active = TRUE
ORDER BY vd.display_order;

-- View: Script summary
CREATE VIEW v_script_summary AS
SELECT
    id, name, filename, is_core, execution_order,
    LENGTH(content) AS content_size,
    (SELECT COUNT(DISTINCT organization_id) FROM organization_variables) AS org_count
FROM scripts
WHERE is_active = TRUE
ORDER BY is_core DESC, execution_order;

-- ============================================================================
-- Grant necessary permissions (adjust for production)
-- ============================================================================
-- GRANT ALL PRIVILEGES ON ALL TABLES IN SCHEMA public TO seeder;
-- GRANT ALL PRIVILEGES ON ALL SEQUENCES IN SCHEMA public TO seeder;

-- ============================================================================
-- End of Schema
-- ============================================================================
