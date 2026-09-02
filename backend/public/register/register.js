/*
 * The onboarding page's behaviour (#781, UC-P01).
 *
 * No framework and no build step, deliberately. This is one screen an
 * anonymous visitor reaches from a QR code on a clubhouse wall; a club
 * upgrading clubbar upgrades it by copying files, and a volunteer changing a
 * sentence should not need a toolchain.
 *
 * ## Three rules this file exists to keep
 *
 * **The secret lives in the fragment and only in memory.** `location.hash` is
 * never sent to a server by any browser, which is why the poster credential
 * goes there rather than in a path — a request line is written verbatim into
 * every access log in front of an installation, and this credential may still
 * be on a wall in two years. It is read once and held in a closure. Nothing
 * here writes to `localStorage`, `sessionStorage` or a cookie.
 *
 * **No personal data leaves this page except to the submission endpoint.** Form
 * state is a variable. The one outbound navigation is the club's own document
 * link, and `<meta name="referrer" content="no-referrer">` keeps this URL —
 * fragment included — out of the club webserver's logs.
 *
 * **The server is the authority.** Everything validated here is validated again
 * there; the client-side checks exist so somebody standing at a bar finds a
 * mistyped IBAN before they submit, not so the server can trust them.
 */
(function () {
  'use strict'

  var API = '/api/public/registrations'

  /* ── i18n ────────────────────────────────────────────────────────────────
     Inline rather than fetched: this page is one round trip on club wifi that
     may barely work, and a locale file that fails to load would leave a form
     labelled with its own key names. The set is small enough to read. */
  var STRINGS = {
    de: {
      loading: 'Einen Moment…',
      languageName: 'Deutsch',
      'brand.fallback': 'Anmeldung',
      'language.title': 'Sprache wählen',
      'paused.title': 'Gerade keine Anmeldung möglich',
      'paused.ask': 'Frag am besten kurz an der Theke nach.',
      'paused.generic': 'Die Anmeldung ist im Moment pausiert.',
      'paused.noDocument': 'Der Verein muss noch seine Unterlagen hinterlegen. Sag bitte an der Theke Bescheid.',
      'unknown.title': 'Dieser Link funktioniert nicht mehr',
      'unknown.body': 'Vielleicht hängt ein neuerer Aushang im Verein. Frag kurz an der Theke nach dem aktuellen Poster.',
      'form.title': 'Anmeldung Vereinsbar',
      'form.notice': 'Vereinsunterlagen und Datenschutzhinweise ansehen',
      'form.firstName': 'Vorname',
      'form.lastName': 'Nachname',
      'form.dateOfBirth': 'Geburtsdatum',
      'form.email': 'E-Mail',
      'form.emailHint': 'Für die Abrechnung. Es wird nichts an dich verschickt.',
      'form.iban': 'IBAN',
      'form.ibanHint': 'Von der Karte oder aus deiner Banking-App.',
      'form.optional': 'Weitere Angaben (optional)',
      'form.phone': 'Telefon',
      'form.accountHolder': 'Kontoinhaber, falls abweichend',
      'form.accountHolderHint': 'Nur wenn jemand anderes das Mandat unterschreibt.',
      'form.review': 'Weiter zur Übersicht',
      'review.title': 'Stimmt das so?',
      'review.lede': 'Prüfe besonders die IBAN — sie steht später auf dem Mandat.',
      'review.confirm': 'Anmeldung absenden',
      'review.back': 'Zurück und ändern',
      'review.sending': 'Wird gesendet…',
      'done.title': 'Geschafft!',
      'done.step1': 'Unterlagen herunterladen, ausdrucken und unterschreiben.',
      'done.step2': 'Das unterschriebene Blatt beim Kassenwart abgeben.',
      'done.step3': 'Der Kassenwart prüft es und schaltet dich frei. Vorher funktioniert das Konto noch nicht.',
      'done.step4': 'Deine Karte bekommst du ebenfalls vom Kassenwart.',
      'done.download': 'Unterlagen herunterladen (PDF)',
      'done.downloadHint': 'Nur jetzt verfügbar. Falls du es verlierst: der Kassenwart kann es jederzeit neu ausdrucken.',
      'done.noDocument': 'Die Unterlagen konnten gerade nicht erzeugt werden — deine Anmeldung ist trotzdem angekommen. Der Kassenwart druckt sie für dich aus.',
      'done.reference': 'Mandatsreferenz',
      'error.required': 'Bitte ausfüllen.',
      'error.email': 'Das sieht nicht wie eine E-Mail-Adresse aus.',
      'error.iban': 'Diese IBAN stimmt nicht. Bitte noch einmal prüfen.',
      'error.date': 'Bitte als TT.MM.JJJJ eingeben.',
      'error.dateFuture': 'Das Geburtsdatum kann nicht in der Zukunft liegen.',
      'error.network': 'Keine Verbindung. Bitte noch einmal versuchen.',
      'error.unexpected': 'Das hat gerade nicht geklappt. Bitte noch einmal versuchen.',
      'error.tooMany': 'Gerade zu viele Anmeldungen. Bitte in ein paar Minuten noch einmal versuchen.',
      'summary.name': 'Name',
      'summary.dateOfBirth': 'Geburtsdatum',
      'summary.email': 'E-Mail',
      'summary.iban': 'IBAN',
      'summary.phone': 'Telefon',
      'summary.accountHolder': 'Kontoinhaber',
      datePlaceholder: 'TT.MM.JJJJ',
    },
    en: {
      loading: 'One moment…',
      languageName: 'English',
      'brand.fallback': 'Registration',
      'language.title': 'Choose a language',
      'paused.title': 'Registration is paused right now',
      'paused.ask': 'Just ask at the bar.',
      'paused.generic': 'Registration is paused at the moment.',
      'paused.noDocument': 'The club still has to publish its documents. Please mention it at the bar.',
      'unknown.title': 'This link no longer works',
      'unknown.body': 'There may be a newer poster in the club. Ask at the bar for the current one.',
      'form.title': 'Join the club bar',
      'form.notice': 'Read the club documents and privacy notice',
      'form.firstName': 'First name',
      'form.lastName': 'Last name',
      'form.dateOfBirth': 'Date of birth',
      'form.email': 'Email',
      'form.emailHint': 'For your statements. Nothing is sent to you now.',
      'form.iban': 'IBAN',
      'form.ibanHint': 'From your card or your banking app.',
      'form.optional': 'More details (optional)',
      'form.phone': 'Phone',
      'form.accountHolder': 'Account holder, if different',
      'form.accountHolderHint': 'Only if somebody else signs the mandate.',
      'form.review': 'Continue to review',
      'review.title': 'Is this right?',
      'review.lede': 'Check the IBAN especially — it goes on the mandate.',
      'review.confirm': 'Send registration',
      'review.back': 'Back to change something',
      'review.sending': 'Sending…',
      'done.title': 'All done!',
      'done.step1': 'Download the documents, print them and sign.',
      'done.step2': 'Hand the signed sheet to the treasurer.',
      'done.step3': 'The treasurer checks it and activates you. Until then your account does not work yet.',
      'done.step4': 'Your card comes from the treasurer too.',
      'done.download': 'Download documents (PDF)',
      'done.downloadHint': 'Available now only. If you lose it, the treasurer can print it again any time.',
      'done.noDocument': 'The documents could not be produced just now — your registration arrived all the same. The treasurer will print them for you.',
      'done.reference': 'Mandate reference',
      'error.required': 'Please fill this in.',
      'error.email': "That does not look like an email address.",
      'error.iban': 'That IBAN is not right. Please check it again.',
      'error.date': 'Please enter it as DD.MM.YYYY.',
      'error.dateFuture': 'A date of birth cannot be in the future.',
      'error.network': 'No connection. Please try again.',
      'error.unexpected': 'That did not work just now. Please try again.',
      'error.tooMany': 'Too many registrations right now. Please try again in a few minutes.',
      'summary.name': 'Name',
      'summary.dateOfBirth': 'Date of birth',
      'summary.email': 'Email',
      'summary.iban': 'IBAN',
      'summary.phone': 'Phone',
      'summary.accountHolder': 'Account holder',
      datePlaceholder: 'DD.MM.YYYY',
    },
  }

  /**
   * The backend's refusal codes, in the member's language.
   *
   * The same philosophy the admin panel's `useApiError` sets out: the backend
   * always writes its `message` in English, and showing that to a member
   * standing at a bar is showing them somebody else's log line. The code
   * travels; the sentence is chosen here.
   */
  var REASONS = {
    registration_disabled: 'paused.generic',
    document_url_missing: 'paused.noDocument',
  }

  var lang = 'de'
  var secret = ''
  var context = null
  var draft = {}

  var $ = function (id) { return document.getElementById(id) }

  function t(key) {
    var table = STRINGS[lang] || STRINGS.de
    return table[key] !== undefined ? table[key] : (STRINGS.de[key] !== undefined ? STRINGS.de[key] : key)
  }

  /* ── branding ─────────────────────────────────────────────────────────────
     The club's name and mark, from the context answer, into the mail layout's
     masthead and footer. Applied once, before any screen is shown: a header
     that arrives after the form has rendered is a header that flashes.

     Nothing here trusts the values. The name is set as `textContent` — it is
     admin-written configuration, like the paused reason beside it — and the
     logo is only ever pointed at what the backend already narrowed to an
     `http(s)` or same-origin URL. A logo that fails to load hides itself again
     rather than leaving a broken-image box in the club's header. */

  function applyBranding(branding) {
    if (!branding) return

    var name = typeof branding.club_name === 'string' ? branding.club_name.trim() : ''
    if (name !== '') {
      var wordmark = $('brand-name')
      // The neutral fallback is a translated string; a real club name is not,
      // so the key goes with it or the next language switch would overwrite
      // the club with "Anmeldung".
      wordmark.removeAttribute('data-i18n')
      wordmark.textContent = name

      $('colophon-name').textContent = name
      $('colophon').hidden = false
    }

    var logo = typeof branding.logo_url === 'string' ? branding.logo_url.trim() : ''
    if (logo !== '') {
      var image = $('brand-logo')
      image.addEventListener('error', function () { image.hidden = true })
      image.src = logo
      image.hidden = false
    }
  }

  function show(id) {
    var screens = document.querySelectorAll('.screen')
    for (var i = 0; i < screens.length; i++) {
      screens[i].hidden = screens[i].id !== id
    }
    window.scrollTo(0, 0)
  }

  /** Apply the current language to every `data-i18n` node. */
  function translate() {
    document.documentElement.lang = lang
    var nodes = document.querySelectorAll('[data-i18n]')
    for (var i = 0; i < nodes.length; i++) {
      // `textContent`, never `innerHTML`: these strings are ours, but the habit
      // is what keeps the club's own `disabled_reason` from ever being rendered
      // as markup by a later edit.
      nodes[i].textContent = t(nodes[i].getAttribute('data-i18n'))
    }
    $('date_of_birth_typed').placeholder = t('datePlaceholder')
  }

  /* ── the date control ─────────────────────────────────────────────────────
     Typed entry, because `<input type="date">` is banned in the admin app for
     reasons that apply here more, not less: on a phone it opens a spinner
     starting at today, and a 1979 birth date is dozens of swipes away. Typing
     `23111979` gives `23.11.1979`. */

  function formatTypedDate(raw) {
    var digits = raw.replace(/\D/g, '').slice(0, 8)
    if (digits.length <= 2) return digits
    if (digits.length <= 4) return digits.slice(0, 2) + '.' + digits.slice(2)
    return digits.slice(0, 2) + '.' + digits.slice(2, 4) + '.' + digits.slice(4)
  }

  /** `23.11.1979` → `1979-11-23`, or null when it is not a real date. */
  function toIsoDate(display) {
    var match = /^(\d{2})\.(\d{2})\.(\d{4})$/.exec(display)
    if (match === null) return null

    var day = Number(match[1])
    var month = Number(match[2])
    var year = Number(match[3])
    var date = new Date(Date.UTC(year, month - 1, day))

    // Round-tripping catches the dates a range check does not: 31.02. is a
    // plausible-looking string and not a day, and `Date` would silently roll it
    // into March rather than refuse it.
    if (
      date.getUTCFullYear() !== year ||
      date.getUTCMonth() !== month - 1 ||
      date.getUTCDate() !== day
    ) {
      return null
    }

    return match[3] + '-' + match[2] + '-' + match[1]
  }

  /** `1979-11-23` → `23.11.1979`, for the review screen and nothing else. */
  function displayDate(iso) {
    var parts = /^(\d{4})-(\d{2})-(\d{2})$/.exec(iso)
    return parts === null ? iso : parts[3] + '.' + parts[2] + '.' + parts[1]
  }

  /* ── the IBAN ─────────────────────────────────────────────────────────────
     Checked here so a typo is caught while the card is still in the visitor's
     hand, at the moment they can fix it. The server checks it again; this is
     kindness, not trust. */

  function groupIban(raw) {
    var compact = raw.replace(/\s+/g, '').toUpperCase().slice(0, 34)
    return compact.replace(/(.{4})/g, '$1 ').trim()
  }

  /**
   * ISO 13616 mod-97.
   *
   * Done in chunks because an IBAN is up to 34 characters and its numeric form
   * is far past what a JavaScript number can hold exactly — a single
   * `Number(...) % 97` is wrong for most real IBANs and right for enough short
   * test values to look correct.
   */
  function ibanIsValid(raw) {
    var iban = raw.replace(/\s+/g, '').toUpperCase()
    if (!/^[A-Z]{2}\d{2}[A-Z0-9]{10,30}$/.test(iban)) return false

    var rearranged = iban.slice(4) + iban.slice(0, 4)
    var expanded = ''
    for (var i = 0; i < rearranged.length; i++) {
      var ch = rearranged.charAt(i)
      expanded += /[A-Z]/.test(ch) ? String(ch.charCodeAt(0) - 55) : ch
    }

    var remainder = 0
    for (var j = 0; j < expanded.length; j += 7) {
      remainder = Number(String(remainder) + expanded.substr(j, 7)) % 97
    }

    return remainder === 1
  }

  /* ── validation ──────────────────────────────────────────────────────────── */

  function setFieldError(name, message) {
    var slot = document.querySelector('[data-error-for="' + name + '"]')
    if (slot !== null) slot.textContent = message || ''

    var input = $(name === 'date_of_birth' ? 'date_of_birth_typed' : name)
    if (input !== null) {
      if (message) {
        input.setAttribute('aria-invalid', 'true')
      } else {
        input.removeAttribute('aria-invalid')
      }
    }
  }

  function clearErrors() {
    var slots = document.querySelectorAll('[data-error-for]')
    for (var i = 0; i < slots.length; i++) setFieldError(slots[i].getAttribute('data-error-for'), '')
    $('form-error').textContent = ''
    $('review-error').textContent = ''
  }

  /** @returns {object|null} the draft, or null when something is wrong. */
  function readForm() {
    clearErrors()

    var values = {
      first_name: $('first_name').value.trim(),
      last_name: $('last_name').value.trim(),
      email: $('email').value.trim(),
      iban: $('iban').value.replace(/\s+/g, '').toUpperCase(),
      phone: $('phone').value.trim(),
      account_holder_name: $('account_holder_name').value.trim(),
      website: $('website').value,
    }

    var ok = true
    var required = ['first_name', 'last_name', 'email', 'iban']
    for (var i = 0; i < required.length; i++) {
      if (values[required[i]] === '') {
        setFieldError(required[i], t('error.required'))
        ok = false
      }
    }

    if (values.email !== '' && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(values.email)) {
      setFieldError('email', t('error.email'))
      ok = false
    }

    if (values.iban !== '' && !ibanIsValid(values.iban)) {
      setFieldError('iban', t('error.iban'))
      ok = false
    }

    var typed = $('date_of_birth_typed').value.trim()
    var iso = toIsoDate(typed)
    if (typed === '') {
      setFieldError('date_of_birth', t('error.required'))
      ok = false
    } else if (iso === null) {
      setFieldError('date_of_birth', t('error.date'))
      ok = false
    } else if (iso > new Date().toISOString().slice(0, 10)) {
      setFieldError('date_of_birth', t('error.dateFuture'))
      ok = false
    } else {
      values.date_of_birth = iso
      $('date_of_birth').value = iso
    }

    if (!ok) {
      var firstBad = document.querySelector('[aria-invalid="true"]')
      if (firstBad !== null) firstBad.focus()
      return null
    }

    return values
  }

  /* ── review ──────────────────────────────────────────────────────────────── */

  function renderReview(values) {
    var rows = [
      ['summary.name', values.first_name + ' ' + values.last_name],
      ['summary.dateOfBirth', displayDate(values.date_of_birth)],
      ['summary.email', values.email],
      ['summary.iban', groupIban(values.iban)],
    ]
    if (values.phone !== '') rows.push(['summary.phone', values.phone])
    if (values.account_holder_name !== '') rows.push(['summary.accountHolder', values.account_holder_name])

    var list = $('review-summary')
    list.textContent = ''
    for (var i = 0; i < rows.length; i++) {
      var dt = document.createElement('dt')
      dt.textContent = t(rows[i][0])
      var dd = document.createElement('dd')
      // `textContent`, so a name containing `<` is a name and not markup.
      dd.textContent = rows[i][1]
      list.appendChild(dt)
      list.appendChild(dd)
    }
  }

  /* ── the two requests ────────────────────────────────────────────────────── */

  function post(path, body) {
    return fetch(path, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      // Nothing about this page is a session, so no cookie is wanted or sent.
      credentials: 'omit',
      body: JSON.stringify(body),
    })
  }

  function renderPaused(reason, message) {
    // The club's own words when it has any, our sentence when it does not.
    // Set as text, never markup: an admin's reason is user input, and #781
    // requires markup inside it to render inert.
    $('paused-reason').textContent = message || t(REASONS[reason] || 'paused.generic')
    show('screen-paused')
  }

  function start() {
    // Read once, from the fragment, and never written anywhere. `substring(1)`
    // rather than a parse: the whole fragment is the secret.
    secret = decodeURIComponent(window.location.hash.replace(/^#/, ''))

    if (secret === '') {
      show('screen-unknown')
      return
    }

    post(API + '/context', { secret: secret })
      .then(function (response) {
        if (response.status === 404) {
          // Uniform: a wrong secret, a missing one and a club that never
          // generated one are indistinguishable, on purpose.
          show('screen-unknown')
          return null
        }
        if (!response.ok) {
          show('screen-unknown')
          return null
        }
        return response.json()
      })
      .then(function (body) {
        if (body === null) return

        context = body
        applyBranding(body)

        if (!body.available) {
          renderPaused(body.reason, body.message)
          return
        }

        $('document-link').href = body.document_url
        renderLanguageChoice(body.languages || ['de'])
      })
      .catch(function () {
        show('screen-unknown')
      })
  }

  function renderLanguageChoice(languages) {
    var choices = $('language-choices')
    choices.textContent = ''

    for (var i = 0; i < languages.length; i++) {
      (function (code) {
        var table = STRINGS[code]
        if (table === undefined) return

        var button = document.createElement('button')
        button.type = 'button'
        button.textContent = table.languageName
        button.setAttribute('data-testid', 'language-' + code)
        button.addEventListener('click', function () {
          lang = code
          translate()
          show('screen-form')
        })
        choices.appendChild(button)
      })(languages[i])
    }

    // One language configured is not a choice; skipping the screen saves a tap
    // that asks nothing.
    if (choices.childElementCount <= 1) {
      lang = languages[0] || 'de'
      translate()
      show('screen-form')
      return
    }

    show('screen-language')
  }

  function submit() {
    var button = document.querySelector('[data-testid="confirm-button"]')
    button.disabled = true
    button.textContent = t('review.sending')
    $('review-error').textContent = ''

    var payload = {
      secret: secret,
      first_name: draft.first_name,
      last_name: draft.last_name,
      email: draft.email,
      date_of_birth: draft.date_of_birth,
      preferred_language: lang,
      iban: draft.iban,
      website: draft.website,
    }
    if (draft.phone !== '') payload.phone = draft.phone
    if (draft.account_holder_name !== '') payload.account_holder_name = draft.account_holder_name

    post(API, payload)
      .then(function (response) {
        return response.json().then(function (body) {
          return { status: response.status, body: body }
        })
      })
      .then(function (result) {
        if (result.status === 201) {
          renderDone(result.body)
          return
        }

        // A club that switched off while the form was open. The same screen the
        // page would have shown on load, from the same reason code — one
        // rendering path, whichever moment the club's decision arrived in.
        if (result.status === 409) {
          renderPaused(result.body.reason, result.body.params && result.body.params.reason)
          return
        }

        if (result.status === 404) {
          show('screen-unknown')
          return
        }

        if (result.status === 429) {
          fail(t('error.tooMany'))
          return
        }

        // A 422 here means the server rejected something this page thought was
        // fine. It is a bug rather than a user mistake, so the member gets a
        // sentence they can act on instead of a field-level accusation.
        fail(t('error.unexpected'))
      })
      .catch(function () {
        fail(t('error.network'))
      })
  }

  function fail(message) {
    var button = document.querySelector('[data-testid="confirm-button"]')
    button.disabled = false
    button.textContent = t('review.confirm')
    $('review-error').textContent = message
  }

  function renderDone(receipt) {
    $('mandate-reference').textContent = receipt.mandate_reference || ''

    var button = $('download-button')
    var missing = $('no-document')

    if (receipt.document) {
      // Held in a closure for as long as this tab lives, and nowhere else. It
      // cannot be re-fetched: the plaintext IBAN it was rendered from existed
      // only for the length of the request that returned it.
      button.hidden = false
      missing.hidden = true
      button.addEventListener('click', function () {
        downloadPdf(receipt.document, 'anmeldung.pdf')
      })
    } else {
      button.hidden = true
      document.querySelector('[data-testid="download-hint"]').hidden = true
      missing.hidden = false
    }

    // The form is cleared on the way out. It is only memory, but a phone handed
    // over the bar to show somebody the reference should not also show them an
    // IBAN.
    draft = {}
    $('registration-form').reset()

    show('screen-done')
  }

  function downloadPdf(base64, filename) {
    var binary = atob(base64)
    var bytes = new Uint8Array(binary.length)
    for (var i = 0; i < binary.length; i++) bytes[i] = binary.charCodeAt(i)

    var url = URL.createObjectURL(new Blob([bytes], { type: 'application/pdf' }))
    var link = document.createElement('a')
    link.href = url
    link.download = filename
    document.body.appendChild(link)
    link.click()
    document.body.removeChild(link)

    // Released on the next tick rather than immediately: revoking synchronously
    // races the download on some mobile browsers, which then save nothing.
    setTimeout(function () { URL.revokeObjectURL(url) }, 60000)
  }

  /* ── wiring ──────────────────────────────────────────────────────────────── */

  document.addEventListener('DOMContentLoaded', function () {
    translate()

    $('date_of_birth_typed').addEventListener('input', function (event) {
      event.target.value = formatTypedDate(event.target.value)
    })

    $('iban').addEventListener('input', function (event) {
      var caretAtEnd = event.target.selectionStart === event.target.value.length
      event.target.value = groupIban(event.target.value)
      if (caretAtEnd) {
        // Grouping inserts spaces; without this the caret jumps backwards every
        // fourth character, which is unusable on a phone.
        event.target.setSelectionRange(event.target.value.length, event.target.value.length)
      }
    })

    $('registration-form').addEventListener('submit', function (event) {
      event.preventDefault()

      var values = readForm()
      if (values === null) return

      draft = values
      renderReview(values)
      show('screen-review')
    })

    document.querySelector('[data-testid="confirm-button"]').addEventListener('click', submit)
    document.querySelector('[data-testid="back-button"]').addEventListener('click', function () {
      show('screen-form')
    })

    start()
  })
})()
