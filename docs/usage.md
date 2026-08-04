# Guia de Uso CLI — moodle-tool_brcli

## Ajuda do Comando de Backup
```bash
sudo -u www-data php admin/tool/brcli/backup.php --help
```

## Backup em Lote por Categoria
```bash
sudo -u www-data php admin/tool/brcli/backup.php --categoryid=5 --destination=/var/backups/moodle/
```

---

## Ajuda do Comando de Restauração
```bash
sudo -u www-data php admin/tool/brcli/restore.php --help
```

## Restauração em Lote
```bash
sudo -u www-data php admin/tool/brcli/restore.php --categoryid=10 --filedir=/var/backups/moodle/
```
