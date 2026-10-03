/*
 * Stimma — lektionsvyn C2 (kortformat). Mall: include/lesson_c2.php.
 *
 * Svar, infosidor och AI-frågor skickas till SAMMA ändpunkter som den klassiska
 * vyn (lesson.php: answer_question_id / mark_info_page_complete, ai_chat.php),
 * så framsteg registreras exakt som förut. Det här skriptet sköter bara visningen.
 */
(function () {
  'use strict';

  var main = document.getElementById('slMain');
  if (!main) return;
  var S = window.STIMMA_LESSON || {};
  var pages = Array.prototype.slice.call(document.querySelectorAll('.sl-page'));
  var doneCard = document.querySelector('[data-sl-done]');
  var counter = document.querySelector('[data-sl-counter]');
  var csrf = main.dataset.csrf;
  var preview = main.dataset.preview === '1';
  var completedBefore = main.dataset.completed === '1';
  var current = 0;
  var lessonDone = completedBefore || preview;
  var quizResult = null;          // senaste serversvaret när alla frågor är klara
  var questionOk = {};            // frågeid → får gå vidare
  var reduceMotion = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

  // ------------------------------------------------------------- bläddring

  function show(idx, focus) {
    if (idx < 0 || idx >= pages.length) return;
    pages.forEach(function (p, i) { p.hidden = i !== idx; });
    if (doneCard) doneCard.hidden = true;
    current = idx;
    if (counter) counter.textContent = (idx + 1) + '/' + pages.length;
    refreshPrimary();
    alignSide();
    window.scrollTo({ top: 0, behavior: reduceMotion ? 'auto' : 'smooth' });
    if (focus !== false) {
      var h = pages[idx].querySelector('.sl-title');
      if (h) h.focus({ preventScroll: true });
    }
  }

  function showDone() {
    if (!doneCard) { goNextPage(); return; }
    pages.forEach(function (p) { p.hidden = true; });
    fillDone();
    doneCard.hidden = false;
    alignSide();
    if (counter) counter.textContent = 'klar';
    window.scrollTo({ top: 0, behavior: reduceMotion ? 'auto' : 'smooth' });
    var h = doneCard.querySelector('.sl-title');
    if (h) h.focus({ preventScroll: true });
  }

  function goNextPage() {
    var n = S.next;
    if (n && n.available) location.href = 'lesson.php?id=' + n.id + (preview ? '&preview=1' : '');
    else location.href = S.courseUrl;
  }

  function page() { return pages[current]; }
  function kind() { return page() ? page().dataset.kind : ''; }
  function primary() { return page() ? page().querySelector('[data-sl-primary]') : null; }
  function question() { return page() ? page().querySelector('[data-sl-question]') : null; }
  function setLabel(btn, text) { var l = btn.querySelector('[data-sl-label]'); if (l) l.textContent = text; }
  function isLastPage() { return current === pages.length - 1; }

  // Primärknappens text på quizsidor beror på svarsläget
  function refreshPrimary() {
    var btn = primary();
    if (!btn || kind() !== 'quiz') return;
    var q = question();
    var ok = q && questionOk[q.dataset.slQuestion];
    if (!ok) { setLabel(btn, 'Svara'); btn.dataset.action = 'answer'; }
    else if (!isLastPage()) { setLabel(btn, 'Nästa fråga'); btn.dataset.action = 'next'; }
    else { setLabel(btn, 'Se resultat'); btn.dataset.action = 'done'; }
  }

  document.addEventListener('click', function (e) {
    var prev = e.target.closest('[data-sl-prev]');
    if (prev) { show(current - 1); return; }
    var btn = e.target.closest('[data-sl-primary]');
    if (btn) { onPrimary(btn); return; }
    if (e.target.closest('[data-sl-skip]')) {
      if (isLastPage()) showDone(); else show(current + 1);
      return;
    }
    var dismiss = e.target.closest('[data-sl-dismiss]');
    if (dismiss) { var el = document.getElementById(dismiss.dataset.slDismiss); if (el) el.hidden = true; }
  });

  function onPrimary(btn) {
    switch (btn.dataset.action) {
      case 'next': show(current + 1); break;
      case 'answer': answer(btn); break;
      case 'done':
        if (lessonDone || (quizResult && quizResult.all_done)) showDone();
        else feedback(question(), 'Svara rätt på alla frågor för att bli klar med lektionen.', false);
        break;
      case 'info-complete': infoComplete(btn); break;
    }
  }

  // Tangentbord: ← → bläddrar, men inte i textfält eller bland svarsalternativ
  document.addEventListener('keydown', function (e) {
    if (e.key !== 'ArrowLeft' && e.key !== 'ArrowRight') return;
    if (e.altKey || e.ctrlKey || e.metaKey || e.shiftKey) return;
    var t = e.target;
    if (t && (t.closest('input, textarea, select, [contenteditable="true"], dialog[open], .sl-side'))) return;
    if (doneCard && !doneCard.hidden) return;
    if (e.key === 'ArrowLeft') { if (current > 0) { e.preventDefault(); show(current - 1); } return; }
    var btn = primary();
    if (btn && btn.dataset.action === 'next') { e.preventDefault(); show(current + 1); }
  });

  // ------------------------------------------------------------- quiz

  function collect(q) {
    var fd = new FormData();
    var any = false;
    q.querySelectorAll('input, select, textarea').forEach(function (el) {
      if (!el.name) return;
      if ((el.type === 'radio' || el.type === 'checkbox') && !el.checked) return;
      if (el.type !== 'hidden' && el.value !== '') any = true;
      if (el.type === 'hidden' && el.value !== '' && el.value !== '[]' && el.value !== '{}') any = true;
      fd.append(el.name, el.value);
    });
    return any ? fd : null;
  }

  function feedback(q, text, right) {
    if (!q) return;
    var f = q.querySelector('[data-sl-feedback]');
    if (!f) return;
    f.textContent = text;
    f.classList.toggle('is-right', right === true);
    f.classList.toggle('is-wrong', right === false);
  }

  function markChoices(q, right, lock) {
    var choices = q.querySelector('.sl-choices');
    if (!choices) return;
    choices.querySelectorAll('.sl-choice').forEach(function (label) {
      var input = label.querySelector('input');
      var status = label.querySelector('.sl-choice-status');
      label.classList.remove('is-right', 'is-wrong');
      if (status) status.textContent = '';
      if (input && input.checked) {
        label.classList.add(right ? 'is-right' : 'is-wrong');
        if (status) status.textContent = right ? '✓ Rätt' : 'Fel';
      }
      if (input) input.disabled = !!lock;
    });
    choices.classList.toggle('is-locked', !!lock);
  }

  function answer(btn) {
    var q = question();
    if (!q) return;
    var fd = collect(q);
    if (!fd) { feedback(q, 'Välj ett svar först.', null); return; }
    fd.append('csrf_token', csrf);
    fd.append('answer_question_id', q.dataset.slQuestion);
    btn.disabled = true;
    fetch(location.pathname + location.search, {
      method: 'POST', body: fd, credentials: 'same-origin',
      headers: { 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-Token': csrf }
    })
      .then(function (r) { return r.json(); })
      .then(function (d) {
        btn.disabled = false;
        if (!d.success) { feedback(q, d.message || 'Något gick fel. Försök igen.', false); return; }
        markChoices(q, d.correct, d.lock_question);
        if (d.lock_question) q.querySelectorAll('.sl-quiz-widget input, .sl-quiz-widget select').forEach(function (el) { el.disabled = true; });
        var ok = d.correct || d.lock_question || preview;
        if (d.correct) feedback(q, 'Rätt svar!', true);
        else if (d.lock_question) feedback(q, 'Fel svar. Ditt svar är sparat.', false);
        else feedback(q, 'Fel svar — försök igen.', false);
        var expl = q.querySelector('[data-sl-explanation]');
        if (expl && ok) expl.hidden = false;
        questionOk[q.dataset.slQuestion] = ok;
        if (d.all_done) {
          quizResult = d;
          lessonDone = true;
          if (d.courseComplete && d.completeUrl) quizResult.completeUrl = d.completeUrl;
        }
        if (d.message && d.all_done && !d.nextLesson && !d.courseComplete) feedback(q, d.message, false);
        refreshPrimary();
        if (ok) btn.focus();
      })
      .catch(function () { btn.disabled = false; feedback(q, 'Nätverksfel. Försök igen.', false); });
  }

  // Nytt val efter ett fel svar: ta bort markeringen så att det inte ser bedömt ut
  main.addEventListener('change', function (e) {
    var q = e.target.closest('[data-sl-question]');
    if (!q || questionOk[q.dataset.slQuestion]) return;
    markChoices(q, false, false);
    q.querySelectorAll('.sl-choice').forEach(function (l) { l.classList.remove('is-wrong', 'is-right'); });
    feedback(q, '', null);
  });

  // ------------------------------------------------------------- infosida

  function infoComplete(btn) {
    btn.disabled = true;
    var fd = new FormData();
    fd.append('csrf_token', csrf);
    fd.append('mark_info_page_complete', '1');
    fetch(location.pathname + location.search, { method: 'POST', body: fd, credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' } })
      .then(function (r) { return r.json(); })
      .then(function (d) {
        if (!d.success) { btn.disabled = false; alertIn(btn, d.message || 'Något gick fel.'); return; }
        var url = d.nextPageUrl || S.courseUrl;
        // Servern lägger själv till preview=1 sedan 2026-10-02 — lägg bara till om det saknas.
        if (preview && url.indexOf('lesson.php') === 0 && !/[?&]preview=1/.test(url)) url += '&preview=1';
        location.href = url;
      })
      .catch(function () { btn.disabled = false; alertIn(btn, 'Nätverksfel. Försök igen.'); });
  }

  function alertIn(btn, text) {
    var p = btn.closest('.sl-body');
    var f = p && p.querySelector('.sl-inline-error');
    if (!f && p) { f = document.createElement('p'); f.className = 'sl-feedback is-wrong sl-inline-error'; f.setAttribute('role', 'alert'); p.insertBefore(f, btn.closest('.sl-nav')); }
    if (f) f.textContent = text;
  }

  // ------------------------------------------------------------- lektion klar

  function fillDone() {
    if (!doneCard) return;
    var total = S.totalLessons || 0;
    var done = (S.doneLessons || 0) + ((!S.thisDoneBefore && lessonDone && !preview) ? 1 : 0);
    if (done > total) done = total;
    doneCard.querySelector('[data-sl-done-count]').textContent = done + ' av ' + total + ' lektioner';
    var bar = doneCard.querySelector('[data-sl-done-bar]');
    bar.setAttribute('aria-valuenow', String(done));
    bar.querySelector('span').style.width = (total ? Math.round(done / total * 100) : 0) + '%';

    var next = S.next;
    if (quizResult && quizResult.nextLesson) {
      next = { id: quizResult.nextLesson.id, title: quizResult.nextLesson.title, available: quizResult.nextLesson.available, info: next && next.id === quizResult.nextLesson.id ? next.info : false };
    }
    var nextBox = doneCard.querySelector('[data-sl-done-next]');
    var go = doneCard.querySelector('[data-sl-done-go]');
    var note = doneCard.querySelector('[data-sl-done-note]');
    var courseDone = (quizResult && quizResult.courseComplete) || (!next && done >= total && total > 0);

    if (courseDone) {
      doneCard.querySelector('[data-sl-done-meta]').textContent = 'Kursen klar';
      nextBox.hidden = true;
      if (quizResult && quizResult.completeUrl) {
        go.hidden = false; go.href = quizResult.completeUrl;
        doneCard.querySelector('[data-sl-done-go-label]').textContent = 'Visa diplom';
      }
    } else if (next) {
      nextBox.hidden = false;
      doneCard.querySelector('[data-sl-done-next-label]').textContent = next.info ? 'Nästa sida' : 'Nästa lektion';
      doneCard.querySelector('[data-sl-done-next-title]').textContent = next.title || '';
      if (next.available) {
        go.hidden = false;
        go.href = 'lesson.php?id=' + next.id + (preview ? '&preview=1' : '');
      } else {
        note.hidden = false;
        note.textContent = 'Nästa lektion låses upp senare.';
      }
    }
  }

  // ------------------------------------------------------------- AI-läraren

  var chatForm = document.querySelector('[data-sl-chatform]');
  var chatLog = document.querySelector('[data-sl-chatlog]');
  var chatInput = document.getElementById('slChatInput');

  function addMsg(html, who) {
    var m = document.createElement('div');
    m.className = 'sl-msg sl-msg--' + who;
    if (who === 'user' || who === 'wait') m.textContent = html; else m.innerHTML = html;   // AI-svaret är sanerat på servern
    chatLog.appendChild(m);
    chatLog.scrollTop = chatLog.scrollHeight;
    return m;
  }

  function ask(text) {
    text = (text || '').trim();
    if (!text || !chatForm) return;
    addMsg(text, 'user');
    chatInput.value = '';
    autosize();
    var send = chatForm.querySelector('.sl-chat-send');
    send.disabled = true;
    var wait = addMsg('AI-läraren skriver…', 'wait');
    fetch('ai_chat.php', {
      method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ lesson_id: S.lessonId, message: text, ai_prompt: main.dataset.aiPrompt || '' })
    })
      .then(function (r) { return r.json(); })
      .then(function (d) { wait.remove(); addMsg(d.response || escapeHtml(d.error || 'Inget svar. Försök igen.'), 'ai'); })
      .catch(function () { wait.remove(); addMsg('Ett fel uppstod. Försök igen senare.', 'ai'); })
      .then(function () { send.disabled = false; });
  }

  function autosize() {
    if (!chatInput) return;
    chatInput.style.height = 'auto';
    chatInput.style.height = Math.min(chatInput.scrollHeight, 160) + 'px';
  }

  if (chatForm) {
    chatForm.addEventListener('submit', function (e) { e.preventDefault(); ask(chatInput.value); });
    chatInput.addEventListener('input', autosize);
    chatInput.addEventListener('keydown', function (e) {
      if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); ask(chatInput.value); }
    });
    document.querySelectorAll('[data-sl-suggest]').forEach(function (b) {
      b.addEventListener('click', function () { ask(b.textContent); });
    });
  }

  function escapeHtml(s) {
    var d = document.createElement('div'); d.textContent = String(s == null ? '' : s); return d.innerHTML;
  }

  // ------------------------------------------------------------- innehåll

  // Sidopanel till vänster om kortet. Kortet behåller alltid sin storlek:
  //   is-docked  — det finns plats bredvid kortet; panelen står i flödet och
  //                valet (öppen/stängd) sparas mellan lektionerna
  //   is-overlay — för smalt; panelen glider in över innehållet och stängs
  //                med Esc, klick utanför eller ett val i listan
  var stage = document.getElementById('slStage');
  var side = document.getElementById('slSide');
  var tocBtn = document.querySelector('[data-sl-toc]');
  var backdrop = document.querySelector('[data-sl-backdrop]');
  var SIDE_KEY = 'stimma-c2-innehall';
  var docked = null;

  function sparat() {
    try { return localStorage.getItem(SIDE_KEY); } catch (e) { return null; }
  }
  function spara(v) {
    try { localStorage.setItem(SIDE_KEY, v ? 'oppen' : 'stangd'); } catch (e) { /* privat läge */ }
  }
  // Panelens bredd anpassas efter platsen bredvid kortet (220–300 px). Får den
  // inte plats med minst 220 px läggs den över innehållet i stället.
  var SIDE_MIN = 220, SIDE_MAX = 300;
  function fitsBeside() {
    if (window.matchMedia('(max-width: 767.98px)').matches) return false;
    var cs = getComputedStyle(stage);
    var cardW = parseFloat(getComputedStyle(main).flexBasis) || 1100;
    // 2 × 16 px: scenens sidomarginal i läget is-docked (se CSS)
    var room = document.documentElement.clientWidth - cardW - parseFloat(cs.getPropertyValue('--sl-side-gap')) - 32;
    if (room < SIDE_MIN) return false;
    stage.style.setProperty('--sl-side-w', Math.min(room, SIDE_MAX) + 'px');
    return true;
  }
  function setSide(open, opts) {
    opts = opts || {};
    side.hidden = !open;
    tocBtn.setAttribute('aria-expanded', open ? 'true' : 'false');
    backdrop.hidden = !(open && !docked);
    if (docked && opts.save) spara(open);
    if (open && !docked) {
      var cur = side.querySelector('.is-current > span, a');
      var closeBtn = side.querySelector('[data-sl-toc-close]');
      (closeBtn || cur).focus();
    }
    if (!open && opts.returnFocus) tocBtn.focus();
    alignSide();
  }
  // Panelen linjerar med kortet: samma överkant (kortet centreras lodrätt på
  // höga skärmar, och meddelanden kan ligga ovanför det) och högst kortets höjd.
  function alignSide() {
    var panel = side && side.querySelector('.sl-side-panel');
    if (!panel) return;
    panel.style.marginTop = ''; panel.style.maxHeight = '';
    if (!docked || side.hidden) return;
    var card = pages.filter(function (p) { return !p.hidden; })[0] || (doneCard && !doneCard.hidden ? doneCard : null);
    if (!card) return;
    // offsetTop, inte getBoundingClientRect: kortet tonar in med en liten
    // förflyttning (sl-fade), och den ska inte mätas in i linjeringen.
    var pTop = side.offsetTop + (parseFloat(getComputedStyle(side).paddingTop) || 0);
    panel.style.marginTop = Math.max(0, card.offsetTop - pTop) + 'px';
    panel.style.maxHeight = 'min(' + card.offsetHeight + 'px, calc(100vh - 32px))';
  }
  function placeSide() {
    var nowDocked = fitsBeside();
    if (nowDocked === docked) return;
    docked = nowDocked;
    stage.classList.toggle('is-docked', docked);
    stage.classList.toggle('is-overlay', !docked);
    // Bredvid: öppen om man inte själv har stängt den. Över innehållet: stängd.
    setSide(docked ? sparat() !== 'stangd' : false);
  }
  if (stage && side && tocBtn && backdrop) {
    placeSide();
    window.addEventListener('resize', function () { placeSide(); alignSide(); });
    tocBtn.addEventListener('click', function () { setSide(side.hidden, { save: true }); });
    side.querySelector('[data-sl-toc-close]').addEventListener('click', function () { setSide(false, { save: true, returnFocus: true }); });
    backdrop.addEventListener('click', function () { setSide(false, { returnFocus: true }); });
    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape' && !docked && !side.hidden) { e.preventDefault(); setSide(false, { returnFocus: true }); }
    });
  } else if (tocBtn) {
    tocBtn.hidden = true;
  }

  // ------------------------------------------------------------- jämn kortstorlek
  // Alla sidor i lektionen får samma höjd som den högsta (högst 900 px), så att
  // kortet inte hoppar i storlek eller läge när man bläddrar.
  // Mobil (< 768 px) har redan helskärmskort och hoppas över.
  function equalize() {
    pages.forEach(function (p) { p.style.minHeight = ''; });
    if (window.matchMedia('(max-width: 767.98px)').matches) return;
    // Mät varje sida på riktigt, en i taget på sin plats — en dold sida som mäts
    // utanför flödet får en annan bredd och därmed andra radbrytningar.
    var tallest = 0;
    var visible = pages.map(function (p) { return !p.hidden; });
    var doneVisible = doneCard && !doneCard.hidden;
    if (doneCard) doneCard.hidden = true;
    pages.forEach(function (p) {
      pages.forEach(function (q) { q.hidden = q !== p; });
      tallest = Math.max(tallest, p.offsetHeight);
    });
    pages.forEach(function (p, i) { p.hidden = !visible[i]; });
    if (doneCard) doneCard.hidden = !doneVisible;
    // Tak på 900 px: en enstaka mycket lång sida ska inte göra alla kort jättehöga.
    // Är kortet högre än fönstret scrollar sidan som vanligt (enligt specen).
    var h = Math.min(tallest, 900);
    pages.forEach(function (p) { p.style.minHeight = h + 'px'; });
    alignSide();
  }
  var eqTimer;
  function equalizeSoon() { clearTimeout(eqTimer); eqTimer = setTimeout(equalize, 120); }
  window.addEventListener('resize', equalizeSoon);
  window.addEventListener('load', equalize);
  if (document.fonts && document.fonts.ready) document.fonts.ready.then(equalize);
  document.querySelectorAll('.sl-page img').forEach(function (img) { if (!img.complete) img.addEventListener('load', equalizeSoon); });
  equalize();

  show(0, false);
})();
