#!/usr/bin/env sh
# A dedicated FPM master for the authenticated canvas captureFrame route only.
# Never point general site traffic at this socket. All other restrictions remain.
set -eu
CANVAS_PHP_BIN="${CANVAS_PHP_BIN:-php}"
CANVAS_FPM_BIN="${CANVAS_FPM_BIN:-php-fpm}"
: "${CANVAS_FPM_CONFIG:?Set the dedicated FPM configuration path}"
CANVAS_DISABLED="$("$CANVAS_FPM_BIN" -i | "$CANVAS_PHP_BIN" -r '
$info=stream_get_contents(STDIN);
if(!preg_match("/^disable_functions\\s*=>\\s*(.*?)\\s*=>/m",$info,$match)){fwrite(STDERR,"Cannot read FPM restrictions\n");exit(1);}
$items=array_unique(array_map("trim",array_merge(explode(",",$match[1]),explode(",",ini_get("disable_functions")))));
$items=array_filter($items,static fn($name)=>!in_array($name,["","no value","proc_open"],true));
echo implode(",",$items);
')"
exec "$CANVAS_FPM_BIN" -F -y "$CANVAS_FPM_CONFIG" -d "disable_functions=$CANVAS_DISABLED"
