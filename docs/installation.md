# Instalação — moodle-tool_brcli

## Passo a Passo

1. Acesse o diretório de ferramentas de administração do Moodle:
   ```bash
   cd /caminho/do/moodle/admin/tool
   ```
2. Clone o repositório nomeando a pasta como `brcli`:
   ```bash
   git clone https://github.com/moodle-by-kelsoncm/moodle-tool_brcli.git brcli
   ```
3. Execute o script de upgrade via CLI do Moodle:
   ```bash
   php admin/cli/upgrade.php --non-interactive
   ```
