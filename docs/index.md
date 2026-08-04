# Visão Geral — moodle-tool_brcli (BrCLI)

O **`moodle-tool_brcli`** (**BrCLI - Backup & Restore Command-Line Interface**) é um plugin de ferramenta de administração (`admin/tool`) para Moodle que permite a administradores realizar backups e restaurações em lote de **categorias inteiras de cursos** via linha de comando.

---

## 🚀 Principais Recursos

- **Backup em Lote por Categoria**: Executa o backup de todos os cursos pertencentes a uma categoria específica com um único comando.
- **Restauração em Lote**: Restaura um conjunto de arquivos de backup `.mbz` em uma categoria de destino definida.
- **Integração CLI Nativa**: Opera em segundo plano via PHP CLI sem estourar o limite de tempo (timeout) do navegador.

---

## 📚 Tópicos da Documentação

- 📦 **[Instalação](installation.md)** — Instalação no diretório `/admin/tool/brcli`.
- ⚙️ **[Parâmetros & Comandos](configuration.md)** — Opções de linha de comando para backup e restauração.
- 📖 **[Guia de Uso CLI](usage.md)** — Exemplos práticos de uso do `backup.php` e `restore.php`.
