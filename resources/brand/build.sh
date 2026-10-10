#!/bin/sh
# Génère les déclinaisons raster du logo depuis les SVG de référence.
set -eu
B=public/brand
S=resources/brand/source
cp $S/logo-horizontal-paths.svg $B/logo-diaba-gescom-horizontal.svg

# Logo horizontal PNG haute définition, fond transparent.
rsvg-convert -h 600 $B/logo-diaba-gescom-horizontal.svg -o $B/logo-diaba-gescom-horizontal.png

# Icône carrée centrée (fond transparent) : marge de sécurité de 8 %.
square() { # taille fichier [fond]
  inner=$(( $1 * 84 / 100 ))
  rsvg-convert -w $inner $B/diaba-icon.svg -o /tmp/inner.png
  magick -size ${1}x${1} xc:"${3:-none}" /tmp/inner.png -gravity center -composite $2
}
square 192 $B/icon-192.png
square 512 $B/icon-512.png

# Variantes à fond opaque bleu nuit (PWA « maskable », Apple) : zone sûre de 80 %.
masked() { # taille fichier
  inner=$(( $1 * 62 / 100 ))
  rsvg-convert -w $inner $B/diaba-icon.svg -o /tmp/inner.png
  magick -size ${1}x${1} xc:"#032D6B" /tmp/inner.png -gravity center -composite $2
}
masked 512 $B/icon-maskable-512.png
masked 180 $B/apple-touch-icon.png

# Favicon multi-tailles.
for s in 16 32 48; do rsvg-convert -w $s -h $s -a $B/diaba-icon.svg -o /tmp/f$s.png; magick /tmp/f$s.png -background none -gravity center -extent ${s}x${s} /tmp/f$s.png; done
magick /tmp/f16.png /tmp/f32.png /tmp/f48.png $B/favicon.ico

# Visuel de partage 1200 × 630.
rsvg-convert -w 960 $B/logo-diaba-gescom-horizontal.svg -o /tmp/og.png
magick -size 1200x630 xc:"#F7F9FC" /tmp/og.png -gravity center -composite $B/og-diaba-gescom.png

ls -l $B
