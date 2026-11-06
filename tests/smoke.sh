#!/usr/bin/env bash
# Local smoke tests for Campus Notes. Run against the app on 127.0.0.1:8000 with the
# freshly imported schema.sql and SYNTHETIC data only. Needs: curl, mariadb/mysql client.
# Usage: LAB_DB_USER=... LAB_DB_PASSWORD=... bash tests/smoke.sh
# Output never prints passwords, session IDs or tokens.
B=${BASE:-http://127.0.0.1:8000}
DBQ() { mysql -N -u"$LAB_DB_USER" -p"$LAB_DB_PASSWORD" -h127.0.0.1 notes_lab -e "$1" 2>/dev/null; }
D=$(mktemp -d); A=$D/alex.jar; S=$D/sam.jar
PASS=0; FAIL=0
check() { # name, expected, actual
  if [ "$2" = "$3" ]; then PASS=$((PASS+1)); echo "PASS  $1 (expected=$2 actual=$3)"
  else FAIL=$((FAIL+1)); echo "FAIL  $1 (expected=$2 actual=$3)"; fi; }
token() { curl -s -b "$1" -c "$1" "$B/" | grep -o 'name="csrf_token" value="[0-9a-f]*"' | head -1 | sed 's/.*value="//;s/"//'; }
code()  { curl -s -o /dev/null -w "%{http_code}" "$@"; }

echo "== Login / auth =="
T=$(token $A)
check "login with valid credentials -> 303" 303 "$(code -b $A -c $A --data-urlencode action=login --data-urlencode email=alex@example.test --data-urlencode password=LabOnly-Alex-123 --data-urlencode csrf_token=$T $B/)"
check "home shows signed-in user" 1 "$(curl -s -b $A $B/ | grep -c 'Signed in as alex@example.test')"
T=$(token $S)
code -b $S -c $S --data-urlencode action=login --data-urlencode email=sam@example.test --data-urlencode password=LabOnly-Sam-456 --data-urlencode csrf_token=$T $B/ >/dev/null
check "second user signed in (separate jar)" 1 "$(curl -s -b $S $B/ | grep -c 'Signed in as sam@example.test')"

J=$D/bad.jar; T=$(token $J)
curl -s -b $J -c $J -L --data-urlencode action=login --data-urlencode email=alex@example.test --data-urlencode password=wrong-password-1 --data-urlencode csrf_token=$T $B/ -o $D/bad.html
check "invalid password -> generic message" 1 "$(grep -c 'Invalid email or password' $D/bad.html)"
check "invalid login does not echo the email" 0 "$(grep -c 'alex@example.test' $D/bad.html)"
J=$D/sqli.jar; T=$(token $J)
curl -s -b $J -c $J -L --data-urlencode action=login --data-urlencode "email=' OR 1=1 -- " --data-urlencode password=x --data-urlencode csrf_token=$T $B/ -o $D/sqli.html
check "SQLi login payload rejected" 0 "$(grep -c 'Signed in as' $D/sqli.html)"

echo "== Registration / role / hashing =="
J=$D/reg.jar; T=$(token $J)
code -b $J -c $J --data-urlencode action=register --data-urlencode email=eve@example.test --data-urlencode password=Synthetic-Pass-789 --data-urlencode role=admin --data-urlencode csrf_token=$T $B/ >/dev/null
check "client-supplied role=admin ignored (stored role)" student "$(DBQ "SELECT role FROM users WHERE email='eve@example.test'")"
check "password stored as bcrypt (prefix \$2y\$)" '$2y$' "$(DBQ "SELECT LEFT(password_hash,4) FROM users WHERE email='eve@example.test'")"
check "no MD5-length hashes remain" 0 "$(DBQ "SELECT COUNT(*) FROM users WHERE LENGTH(password_hash)=32")"
T=$(token $J)
curl -s -b $J -c $J -L --data-urlencode action=register --data-urlencode email=short@example.test --data-urlencode password=abc --data-urlencode csrf_token=$T $B/ -o $D/short.html
check "short password rejected" 0 "$(DBQ "SELECT COUNT(*) FROM users WHERE email='short@example.test'")"

echo "== Session handling =="
J=$D/fix.jar; curl -s -c $J -o /dev/null $B/; BEFORE=$(grep CAMPUSNOTESSID $J | awk '{print $7}')
T=$(token $J)
code -b $J -c $J --data-urlencode action=login --data-urlencode email=alex@example.test --data-urlencode password=LabOnly-Alex-123 --data-urlencode csrf_token=$T $B/ >/dev/null
AFTER=$(grep CAMPUSNOTESSID $J | awk '{print $7}')
check "session ID changes after login" different "$([ "$BEFORE" != "$AFTER" ] && echo different || echo same)"
HDR=$(curl -s -D - -o /dev/null $B/ | grep -i '^set-cookie')
check "cookie has HttpOnly" 1 "$(echo "$HDR" | grep -ci httponly)"
check "cookie has SameSite" 1 "$(echo "$HDR" | grep -ci samesite)"

echo "== Authorization (web) =="
check "Alex sees Alex's notes" 2 "$(curl -s -b $A $B/ | grep -c 'Alex: ')"
check "Alex sees none of Sam's notes" 0 "$(curl -s -b $A $B/ | grep -c 'Sam: ')"
check "Alex cannot open Sam's note for edit (id 3)" 0 "$(curl -s -b $A "$B/?edit=3" | grep -c 'Edit note')"
T=$(token $A)
curl -s -b $A -c $A -L --data-urlencode action=update --data id=3 --data-urlencode title=HACKED --data-urlencode body=HACKED --data-urlencode csrf_token=$T $B/ -o $D/upd.html
check "Alex update of Sam's note -> 'Note not found'" 1 "$(grep -c 'Note not found' $D/upd.html)"
check "Sam's note 3 unchanged in DB" "Sam: study plan" "$(DBQ 'SELECT title FROM notes WHERE id=3')"
T=$(token $A)
curl -s -b $A -c $A -L --data-urlencode action=delete --data id=3 --data-urlencode csrf_token=$T $B/ -o /dev/null
check "Alex delete of Sam's note had no effect" 1 "$(DBQ 'SELECT COUNT(*) FROM notes WHERE id=3')"
T=$(token $A)
curl -s -b $A -c $A -L --data-urlencode action=save --data user_id=2 --data-urlencode "title=owner test" --data-urlencode body=x --data-urlencode csrf_token=$T $B/ -o /dev/null
check "posted user_id=2 ignored; note owned by session user (1)" 1 "$(DBQ "SELECT user_id FROM notes WHERE title='owner test'")"
T=$(token $A)
check "Alex can delete own note" 1 "$(curl -s -b $A -c $A -L --data-urlencode action=delete --data id=$(DBQ "SELECT id FROM notes WHERE title='owner test'") --data-urlencode csrf_token=$T $B/ | grep -c 'Note deleted')"

echo "== XSS =="
T=$(token $A)
PAYLOAD='<script>alert(1)</script><img src=x onerror=alert(2)>'
curl -s -b $A -c $A -o /dev/null --data-urlencode action=save --data-urlencode "title=$PAYLOAD" --data-urlencode "body=$PAYLOAD" --data-urlencode csrf_token=$T $B/
PAGE=$(curl -s -b $A $B/)
check "raw <script> not present in HTML" 0 "$(echo "$PAGE" | grep -c '<script>alert')"
check "payload shown escaped as text" 1 "$(echo "$PAGE" | grep -c '&lt;script&gt;alert(1)&lt;/script&gt;' | sed 's/[2-9]/1/')"
check "CSP header present" 1 "$(curl -s -D - -o /dev/null $B/ | grep -ci '^content-security-policy')"
check "no ?message= reflection" 0 "$(curl -s "$B/?message=%3Cscript%3Ex%3C/script%3E" | grep -c '<script>x')"
DBQ "DELETE FROM notes WHERE title LIKE '%alert%'" >/dev/null

echo "== CSRF / unsafe methods (web) =="
check "POST without token -> 403" 403 "$(code -b $A --data-urlencode action=save --data-urlencode title=t --data-urlencode body=b $B/)"
check "POST with wrong token -> 403" 403 "$(code -b $A --data-urlencode action=save --data-urlencode title=t --data-urlencode body=b --data csrf_token=deadbeef $B/)"
check "GET ?delete=4 no longer deletes" 1 "$(code -b $S "$B/?delete=4" >/dev/null; DBQ 'SELECT COUNT(*) FROM notes WHERE id=4')"
check "GET ?logout=1 does not log out" 1 "$(code -b $A "$B/?logout=1" >/dev/null; curl -s -b $A $B/ | grep -c 'Signed in as')"

echo "== API =="
check "GET no session -> 401" 401 "$(code "$B/api.php?user_id=2")"
check "POST no session -> 401" 401 "$(code -H 'Content-Type: application/json' -d '{"id":3,"title":"x","body":"y"}' $B/api.php)"
check "GET own notes -> 200" 200 "$(code -b $A "$B/api.php?user_id=1")"
check "GET other user's id -> 403" 403 "$(code -b $A "$B/api.php?user_id=2")"
check "GET other user's id leaks no notes" 0 "$(curl -s -b $A "$B/api.php?user_id=2" | grep -c 'Sam')"
check "GET no param returns only own notes (no Sam)" 0 "$(curl -s -b $A "$B/api.php" | grep -c 'Sam')"
check "GET user_id=abc -> 400" 400 "$(code -b $A "$B/api.php?user_id=abc")"
AT=$(curl -s -b $A -c $A "$B/api.php?csrf=1" | sed 's/.*"csrf_token":"\([0-9a-f]*\)".*/\1/')
post() { curl -s -o /dev/null -w "%{http_code}" -b $A -H "Content-Type: application/json" -H "X-CSRF-Token: $AT" "$@" $B/api.php; }
check "POST own note with token -> 200" 200 "$(post -d '{"id":1,"title":"Alex: course reminder","body":"Review the security reading before class."}')"
check "POST other user's note -> 404" 404 "$(post -d '{"id":3,"title":"HACKED","body":"HACKED"}')"
check "Sam's note 3 unchanged after API attempt" "Sam: study plan" "$(DBQ 'SELECT title FROM notes WHERE id=3')"
check "POST without CSRF token -> 403" 403 "$(code -b $A -H 'Content-Type: application/json' -d '{"id":1,"title":"x","body":"y"}' $B/api.php)"
check "POST wrong content type -> 415" 415 "$(curl -s -o /dev/null -w '%{http_code}' -b $A -H 'Content-Type: text/plain' -H "X-CSRF-Token: $AT" -d '{"id":1,"title":"x","body":"y"}' $B/api.php)"
check "malformed JSON -> 400" 400 "$(post -d '{"id":1,"title":')"
check "JSON list instead of object -> 400" 400 "$(post -d '[1,2,3]')"
check "JSON scalar -> 400" 400 "$(post -d '"hello"')"
check "empty body -> 400" 400 "$(post -d '')"
check "missing/invalid id -> 422" 422 "$(post -d '{"id":"1 OR 1=1","title":"x","body":"y"}')"
check "title as array -> 422" 422 "$(post -d '{"id":1,"title":["a"],"body":"y"}')"
check "title too long -> 422" 422 "$(post -d "{\"id\":1,\"title\":\"$(head -c 130 /dev/zero | tr '\0' a)\",\"body\":\"y\"}")"
# Send the big body from a file: Windows/Git Bash cannot pass a 70 KB command-line argument.
BIG=$D/big.json; { printf '{"id":1,"title":"x","body":"'; head -c 70000 /dev/zero | tr '\0' a; printf '"}'; } > "$BIG"
check "oversized body -> 413" 413 "$(post --data-binary @"$BIG")"
post -d '{"id":1,"title":"mass assign","body":"y","user_id":2,"role":"admin"}' >/dev/null
check "mass-assigned user_id ignored (note 1 still owner 1)" 1 "$(DBQ 'SELECT user_id FROM notes WHERE id=1')"
DBQ "UPDATE notes SET title='Alex: course reminder', body='Review the security reading before class.' WHERE id=1" >/dev/null
check "DELETE method -> 405" 405 "$(code -b $A -X DELETE $B/api.php)"

echo "== API cross-origin =="
check "untrusted Origin POST -> 403" 403 "$(curl -s -o /dev/null -w '%{http_code}' -b $A -H 'Origin: http://evil.test' -H 'Content-Type: application/json' -H "X-CSRF-Token: $AT" -d '{"id":1,"title":"x","body":"y"}' $B/api.php)"
check "untrusted Origin GET -> 403" 403 "$(curl -s -o /dev/null -w '%{http_code}' -b $A -H 'Origin: http://evil.test' "$B/api.php")"
check "no Access-Control-Allow-Origin on untrusted origin" 0 "$(curl -s -D - -o /dev/null -b $A -H 'Origin: http://evil.test' $B/api.php | grep -ci '^access-control')"
check "no Access-Control-* headers on normal response" 0 "$(curl -s -D - -o /dev/null -b $A -H 'Origin: http://127.0.0.1:8000' $B/api.php | grep -ci '^access-control')"
check "preflight OPTIONS gets no CORS approval" 0 "$(curl -s -D - -o /dev/null -X OPTIONS -H 'Origin: http://evil.test' -H 'Access-Control-Request-Method: POST' $B/api.php | grep -ci '^access-control-allow')"
check "Sec-Fetch-Site: cross-site -> 403" 403 "$(curl -s -o /dev/null -w '%{http_code}' -b $A -H 'Sec-Fetch-Site: cross-site' $B/api.php)"
check "cross-user CSRF token not usable (Sam's session, Alex's token)" 403 "$(curl -s -o /dev/null -w '%{http_code}' -b $S -H 'Content-Type: application/json' -H "X-CSRF-Token: $AT" -d '{"id":3,"title":"x","body":"y"}' $B/api.php)"

echo "== Logout =="
T=$(token $A)
code -b $A -c $A --data-urlencode action=logout --data-urlencode csrf_token=$T $B/ >/dev/null
check "after logout, web shows login form" 0 "$(curl -s -b $A $B/ | grep -c 'Signed in as')"
check "after logout, API -> 401" 401 "$(code -b $A "$B/api.php")"
# replay the OLD session id (copied before logout) to prove it is dead server-side
OLD=$(grep CAMPUSNOTESSID $S | awk '{print $7}')
T=$(token $S)
code -b $S -c $S --data-urlencode action=logout --data-urlencode csrf_token=$T $B/ >/dev/null
check "old session ID replayed after logout -> 401" 401 "$(code -H "Cookie: CAMPUSNOTESSID=$OLD" $B/api.php)"

echo; echo "RESULT: $PASS passed, $FAIL failed"
rm -rf "$D"
[ "$FAIL" -eq 0 ]
