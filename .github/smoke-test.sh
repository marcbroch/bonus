#!/usr/bin/env bash
# Kurztest der App: einrichten, als Eltern und Kind anmelden, alle Seiten aufrufen.
# Schlägt fehl bei HTTP-Fehlern, PHP-Fehlern/-Warnungen oder Einträgen in data/error.log.
set -u
cd "$(dirname "$0")/.."
TMP=$(mktemp -d); PORT=8099; BASE="http://127.0.0.1:$PORT/index.php"
rm -f data/bonus.sqlite data/error.log
php -d display_errors=1 -d error_reporting=-1 -S 127.0.0.1:$PORT >"$TMP/server.log" 2>&1 &
SERVER=$!; trap 'kill $SERVER 2>/dev/null; rm -f data/bonus.sqlite data/error.log' EXIT
sleep 1
FAIL=0
csrf() { grep -o 'name="csrf" value="[a-f0-9]*"' "$1" | head -1 | sed 's/.*value="//; s/"//'; }
get() { # get <cookiejar> <query>
  code=$(curl -s -b "$1" -c "$1" -o "$TMP/page" -w "%{http_code}" "$BASE?$2")
  if [ "$code" != "200" ] || grep -qE "Fatal error|Warning:|Notice:|Deprecated:|Parse error|Hoppla" "$TMP/page"; then
    echo "FEHLER  ?$2  (HTTP $code)"; grep -oE "(Fatal error|Warning|Notice|Deprecated|Parse error):.{0,200}" "$TMP/page" | head -3; FAIL=1
  else echo "ok      ?$2"; fi
}
post() { curl -s -b "$1" -c "$1" -o "$TMP/page" -w "%{http_code}" "$BASE?$2" "${@:3}" >/dev/null; }

# Einrichtung
P="$TMP/parent"
curl -s -c "$P" -o "$TMP/page" "$BASE"
post "$P" "" --data-urlencode "csrf=$(csrf "$TMP/page")" -d "name=Testeltern" -d "password=geheim123"
get "$P" "p=admin_settings&s=people"
post "$P" "p=admin_settings" --data-urlencode "csrf=$(csrf "$TMP/page")" -d "s=people" -d "id=1" -d "name=Bent" \
  -d "password=bent123" -d "can_login=on" -d "active=on" -d "action=save_person"
for pg in "p=admin" "p=admin&w=-1" "p=admin_enter" "p=admin_overview" "p=account&u=1" \
          "p=admin_settings&s=people" "p=admin_settings&s=tasks" "p=admin_settings&s=rewards"; do get "$P" "$pg"; done

# Kind
K="$TMP/kid"
curl -s -c "$K" -o "$TMP/page" "$BASE?p=login"
post "$K" "p=login" --data-urlencode "csrf=$(csrf "$TMP/page")" -d "uid=1" -d "password=bent123"
for pg in "p=home" "p=home&w=-2" "p=submit" "p=submit&t=1" "p=rewards" "p=account"; do get "$K" "$pg"; done
grep -q "Hallo Bent" <(curl -s -b "$K" "$BASE?p=home") || { echo "FEHLER  Kind-Anmeldung hat nicht geklappt"; FAIL=1; }
get "$P" "p=password"

# Passwort ändern: falsches altes Passwort wird abgelehnt, richtiges klappt, neues gilt beim Anmelden
get "$K" "p=password"
post "$K" "p=password" --data-urlencode "csrf=$(csrf "$TMP/page")" -d "old=falsch" -d "new=neu4567" -d "new2=neu4567"
grep -q "stimmt nicht" "$TMP/page" || { echo "FEHLER  falsches altes Passwort wurde nicht abgelehnt"; FAIL=1; }
post "$K" "p=password" --data-urlencode "csrf=$(csrf "$TMP/page")" -d "old=bent123" -d "new=neu4567" -d "new2=neu4567"
K2="$TMP/kid2"
curl -s -c "$K2" -o "$TMP/page" "$BASE?p=login"
post "$K2" "p=login" --data-urlencode "csrf=$(csrf "$TMP/page")" -d "uid=1" -d "password=neu4567"
if grep -q "Hallo Bent" <(curl -s -b "$K2" "$BASE?p=home"); then echo "ok      Passwort ändern"; else echo "FEHLER  Anmeldung mit neuem Passwort klappt nicht"; FAIL=1; fi

if [ -s data/error.log ]; then echo "FEHLER  data/error.log:"; cat data/error.log; FAIL=1; fi
if grep -qE "PHP (Fatal|Warning|Notice|Deprecated|Parse)" "$TMP/server.log"; then echo "FEHLER  Server-Log:"; grep -E "PHP " "$TMP/server.log" | head; FAIL=1; fi
[ $FAIL = 0 ] && echo "Alles in Ordnung (PHP $(php -r 'echo PHP_VERSION;'))"
exit $FAIL
