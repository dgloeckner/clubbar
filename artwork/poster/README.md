# Sommerfest-Poster (A4)

Ein Aushang, der die Clubbar am Vereins-Sommerfest erklärt und zum Ausprobieren
einlädt: Terminal und Adminpanel stehen als Testumgebung bereit, es entstehen
keine Kosten und es wird nichts abgebucht.

| Datei | Wofür |
|---|---|
| `clubbar-sommerfest-a4-sw.pdf` | **Zum Drucken.** Graustufen, für Schwarzweiß-Laserdrucker |
| `clubbar-sommerfest-a4.pdf` | Farbe — für Bildschirm, Beamer oder einen Farbdruck |
| `clubbar-sommerfest-a4*.png` | Vorschaubilder, z. B. für eine Mail an die Mitglieder |
| `clubbar-sommerfest-a4.html` | Die Quelle. Hier wird der Text geändert |
| `build-poster.mjs` | Erzeugt aus der Quelle beide PDFs und beide PNGs |

Drucken: **100 % / Originalgröße**, nicht „an Seitenrand anpassen“ — das Poster
ist randlos gesetzt und die Bänder oben und unten sollen bis an die Kante laufen.
Wenn der Drucker keinen randlosen Druck kann, ist „an Druckbereich anpassen“ die
richtige Option; dann bleibt ringsum ein schmaler weißer Rand.

## Ändern und neu bauen

```bash
node artwork/poster/build-poster.mjs
```

Braucht Node 22 und das Chromium, das mit Playwright kommt
(`/opt/pw-browsers/chromium`, sonst `CHROMIUM_BIN=… node …`). Sonst nichts —
keine npm-Abhängigkeiten.

Das Skript prüft zwei Dinge und bricht mit Exit-Code 1 ab, wenn eines davon
nicht stimmt:

- **Passt der Inhalt auf eine Seite?** Das Poster ist auf genau eine A4-Seite
  geschnitten. Eine Textänderung, die zwei Zeilen mehr braucht, würde den Fuß
  sonst lautlos abschneiden statt eine sichtbare zweite Seite zu erzeugen.
- **Ist die Schrift geladen?** Ohne Inter fällt das Layout auf Arial zurück und
  sieht nur *fast* richtig aus.

## Die zwei Fassungen

Farbe und Graustufen kommen aus derselben HTML-Datei. Jede Farbe steht als
CSS-Custom-Property in `:root`; `<html class="mono">` überschreibt genau diese
Properties mit ausgesuchten Grauwerten, und die beiden Logos rechnet
`build-poster.mjs` auf ihre Luminanz um.

Der Umweg lohnt sich, weil ein `filter: grayscale(1)` über die ganze Seite
Chromium dazu bringt, alles zu rastern — das PDF wird groß und die Schrift
weich. So bleiben beide PDFs reiner Vektor mit eingebetteter Schrift.

Der Nebeneffekt ist der eigentliche Punkt: die Graustufen sind gesetzt, nicht
ausgerechnet. Farbe trägt im Poster nirgends eine Information allein — jeder
Akzent unterscheidet sich auch in der Helligkeit, deshalb funktioniert der
Aushang am Schwarzweißdrucker genauso wie am Bildschirm.

## Das FRGS-Wappen

Im Kopf ist ein Platz für den Vereinsadler. Solange die Datei fehlt, steht dort
ein schlichter „FRGS“-Schriftzug, und das Skript sagt beim Bauen Bescheid.

Zum Einsetzen die weiße SVG-Fassung als `artwork/frgs-adler.svg` ablegen und neu
bauen — mehr ist nicht nötig, die Graustufen-Variante entsteht automatisch mit:

```bash
curl -o artwork/frgs-adler.svg https://www.rudern-in-frankfurt.de/assets/img/adler-white.svg
node artwork/poster/build-poster.mjs
```

Die weiße Fassung ist die richtige: das Wappen steht auf dem dunklen Kopfband.

## Schriften

`fonts/` enthält Inter (SIL Open Font License 1.1) als woff2-Subsets. Sie liegen
im Repo, damit der Build ohne Netz läuft und das Poster in einem Jahr noch
genauso aussieht.
