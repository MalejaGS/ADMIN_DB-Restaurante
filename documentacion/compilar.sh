#!/usr/bin/env bash
set -euo pipefail

DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
cd "$DIR"

echo "=== Compilador de Documento de Entrega en LaTeX ==="

if command -v pdflatex >/dev/null 2>&1; then
    echo "Compilando con pdflatex nativo..."
    pdflatex -interaction=nonstopmode documento_entrega.tex
    pdflatex -interaction=nonstopmode documento_entrega.tex
    echo "Compilación exitosa: $DIR/documento_entrega.pdf"
    exit 0
fi

echo "pdflatex local no detectado en el sistema."
echo "Opciones recomendadas:"
echo " 1. Subir la carpeta 'documentacion/' a Overleaf (https://www.overleaf.com) y compilar directamente."
echo " 2. Instalar texlive en el sistema:"
echo "    sudo dnf install -y texlive-scheme-medium"
echo " 3. Usar contenedor Docker con texlive:"
echo "    docker run --rm -v \"$DIR\":/workdir -w /workdir texlive/texlive pdflatex documento_entrega.tex"

