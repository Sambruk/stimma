/**
 * Stimma — klient-interaktion för typade quizfrågor.
 *
 * Hanterar drag-drop för order, match_pairs, categorize samt hotspot-klick.
 * Vid submit samlas tillståndet i hidden inputs så servern kan utvärdera.
 */
(function() {
  'use strict';

  // ============ ORDER (sortable list) ============
  document.querySelectorAll('.order-list').forEach(function(list) {
    var dragEl = null;
    list.querySelectorAll('li').forEach(function(li) {
      li.addEventListener('dragstart', function(e) {
        dragEl = li;
        li.style.opacity = '0.4';
        if (e.dataTransfer) e.dataTransfer.effectAllowed = 'move';
      });
      li.addEventListener('dragend', function() {
        li.style.opacity = '';
        dragEl = null;
        updateOrderValue(list);
      });
      li.addEventListener('dragover', function(e) {
        e.preventDefault();
        if (!dragEl || dragEl === li) return;
        var rect = li.getBoundingClientRect();
        var next = (e.clientY - rect.top) / rect.height > 0.5;
        li.parentNode.insertBefore(dragEl, next ? li.nextSibling : li);
      });
    });
    updateOrderValue(list);
  });

  function updateOrderValue(list) {
    var name = list.getAttribute('data-name');
    if (!name) return;
    var input = list.parentNode.querySelector('input[name="' + name + '"]');
    if (!input) return;
    var idxs = [];
    list.querySelectorAll('li').forEach(function(li) {
      idxs.push(parseInt(li.getAttribute('data-idx'), 10));
    });
    input.value = JSON.stringify(idxs);
  }

  // ============ MATCH PAIRS (drag from pool → dropzone) ============
  document.querySelectorAll('.match-pairs').forEach(function(container) {
    var dragEl = null;
    container.querySelectorAll('.match-draggable').forEach(function(d) {
      d.addEventListener('dragstart', function(e) {
        dragEl = d;
        d.style.opacity = '0.4';
        if (e.dataTransfer) e.dataTransfer.effectAllowed = 'move';
      });
      d.addEventListener('dragend', function() { d.style.opacity = ''; dragEl = null; updateMatchValue(container); });
    });

    // Dropzones: rad-dropzones (höger om left) OCH pool:en (för att lägga tillbaka)
    var zones = container.querySelectorAll('.match-dropzone, .match-pool');
    zones.forEach(function(zone) {
      zone.addEventListener('dragover', function(e) { e.preventDefault(); zone.classList.add('bg-warning-subtle'); });
      zone.addEventListener('dragleave', function() { zone.classList.remove('bg-warning-subtle'); });
      zone.addEventListener('drop', function(e) {
        e.preventDefault();
        zone.classList.remove('bg-warning-subtle');
        if (!dragEl) return;
        // Om dropzone redan har ett barn, flytta det tillbaka till poolen
        if (zone.classList.contains('match-dropzone')) {
          var existing = zone.querySelector('.match-draggable');
          var pool = container.querySelector('.match-pool');
          if (existing && pool) pool.appendChild(existing);
        }
        zone.appendChild(dragEl);
        updateMatchValue(container);
      });
    });
    updateMatchValue(container);
  });

  function updateMatchValue(container) {
    var name = container.getAttribute('data-name');
    if (!name) return;
    var input = container.parentNode.querySelector('input[name="' + name + '"]');
    if (!input) return;
    var mapping = {};
    container.querySelectorAll('.match-row').forEach(function(row) {
      var leftIdx = parseInt(row.getAttribute('data-left-idx'), 10);
      var dragged = row.querySelector('.match-draggable');
      if (dragged) {
        mapping[leftIdx] = parseInt(dragged.getAttribute('data-right-idx'), 10);
      }
    });
    input.value = JSON.stringify(mapping);
  }

  // ============ CATEGORIZE (drag from pool → category bucket) ============
  document.querySelectorAll('.categorize-widget').forEach(function(container) {
    var dragEl = null;
    container.querySelectorAll('.cat-item').forEach(function(it) {
      it.addEventListener('dragstart', function(e) {
        dragEl = it;
        it.style.opacity = '0.4';
        if (e.dataTransfer) e.dataTransfer.effectAllowed = 'move';
      });
      it.addEventListener('dragend', function() { it.style.opacity = ''; dragEl = null; updateCatValue(container); });
    });
    // Hela kategorirutan är släppzon. Tidigare var det bara det inre
    // .cat-bucket-items, som är 0 px högt när det är tomt — ett släpp mitt på
    // den synliga rutan registrerades aldrig.
    var zones = container.querySelectorAll('.cat-bucket, .categorize-pool');
    zones.forEach(function(zone) {
      zone.addEventListener('dragover', function(e) { e.preventDefault(); zone.classList.add('bg-warning-subtle'); });
      zone.addEventListener('dragleave', function(e) { if (!zone.contains(e.relatedTarget)) zone.classList.remove('bg-warning-subtle'); });
      zone.addEventListener('drop', function(e) {
        e.preventDefault();
        zone.classList.remove('bg-warning-subtle');
        if (!dragEl) return;
        (zone.querySelector('.cat-bucket-items') || zone).appendChild(dragEl);
        updateCatValue(container);
      });
    });
    updateCatValue(container);
  });

  function updateCatValue(container) {
    var name = container.getAttribute('data-name');
    if (!name) return;
    var input = container.parentNode.querySelector('input[name="' + name + '"]');
    if (!input) return;
    var mapping = {};
    container.querySelectorAll('.cat-bucket').forEach(function(bucket) {
      var catIdx = parseInt(bucket.getAttribute('data-cat-idx'), 10);
      bucket.querySelectorAll('.cat-item').forEach(function(it) {
        var itemIdx = parseInt(it.getAttribute('data-item-idx'), 10);
        mapping[itemIdx] = catIdx;
      });
    });
    input.value = JSON.stringify(mapping);
  }

  // ============ KLICKA FÖR ATT PLACERA (alternativ till att dra) ============
  // HTML5-dra-och-släpp fungerar inte på pekskärm och inte med tangentbord.
  // Därför kan man också klicka (eller trycka Enter/mellanslag) på ett objekt
  // och sedan på platsen det ska till. Samma dolda fält uppdateras som vid drag.
  (function injectStyle() {
    if (document.getElementById('quizPickStyle')) return;
    var st = document.createElement('style');
    st.id = 'quizPickStyle';
    st.textContent =
      '.quiz-pickable{cursor:pointer}' +
      '.quiz-picked{outline:3px solid #0F3B5F;outline-offset:2px;box-shadow:0 0 0 6px rgba(15,59,95,.12)}' +
      '.quiz-pick-active .quiz-target{outline:2px dashed #0F3B5F;outline-offset:2px;cursor:pointer}' +
      '.order-move{min-width:44px;min-height:44px;border:1px solid #c9c6bd;border-radius:10px;background:#fff;color:#1a1a19;' +
      'display:inline-flex;align-items:center;justify-content:center;font-size:18px;line-height:1;margin-left:4px;cursor:pointer}' +
      '.order-move:disabled{opacity:.35;cursor:default}' +
      '.order-list li{gap:4px}.order-list li>span{flex:1 1 auto}';
    document.head.appendChild(st);
  })();

  function isLocked(widget) {
    var q = widget.closest('.quiz-question') || widget.parentNode;
    var hidden = q && q.querySelector('input[type="hidden"][name]');
    return !!(hidden && hidden.disabled);
  }

  function makeButtonLike(el, label) {
    el.setAttribute('tabindex', '0');
    el.setAttribute('role', 'button');
    if (label) el.setAttribute('aria-label', label);
    el.addEventListener('keydown', function(e) {
      if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); el.click(); }
    });
  }

  function setupPickAndPlace(widget, itemSel, targetSel, place, update) {
    var picked = null;
    function clear() {
      if (picked) { picked.classList.remove('quiz-picked'); picked.setAttribute('aria-pressed', 'false'); }
      picked = null;
      widget.classList.remove('quiz-pick-active');
    }
    widget.querySelectorAll(itemSel).forEach(function(it) {
      it.classList.add('quiz-pickable');
      makeButtonLike(it);
      it.setAttribute('aria-pressed', 'false');
      it.addEventListener('click', function(e) {
        if (isLocked(widget)) return;
        e.stopPropagation();
        if (picked === it) { clear(); return; }
        // Ett annat objekt är valt och man klickar på ett objekt som redan ligger
        // i en kategori/ett par: menat är "lägg det valda här", inte "byt val".
        var hem = it.closest(targetSel);
        if (picked && hem && !hem.classList.contains('categorize-pool') && !hem.classList.contains('match-pool')) {
          var valt = picked;
          clear();
          place(hem, valt);
          update(widget);
          valt.focus();
          return;
        }
        clear();
        picked = it;
        it.classList.add('quiz-picked');
        it.setAttribute('aria-pressed', 'true');
        widget.classList.add('quiz-pick-active');
      });
    });
    widget.querySelectorAll(targetSel).forEach(function(t) {
      t.classList.add('quiz-target');
      t.addEventListener('click', function(e) {
        if (!picked || isLocked(widget)) return;
        if (e.target.closest(itemSel) && e.target.closest(itemSel) !== picked) return;
        var it = picked;
        clear();
        place(t, it);
        update(widget);
        it.focus();
      });
      t.addEventListener('keydown', function(e) {
        if ((e.key === 'Enter' || e.key === ' ') && picked && e.target === t) { e.preventDefault(); t.click(); }
      });
    });
    document.addEventListener('keydown', function(e) { if (e.key === 'Escape') clear(); });
  }

  document.querySelectorAll('.categorize-widget').forEach(function(widget) {
    widget.querySelectorAll('.cat-bucket').forEach(function(b) {
      var namn = (b.querySelector('.fw-semibold') || b).textContent.trim();
      makeButtonLike(b, 'Lägg det valda objektet i kategorin ' + namn);
    });
    var pool = widget.querySelector('.categorize-pool');
    if (pool) makeButtonLike(pool, 'Lägg tillbaka det valda objektet bland de osorterade');
    setupPickAndPlace(widget, '.cat-item', '.cat-bucket, .categorize-pool', function(target, it) {
      (target.querySelector('.cat-bucket-items') || target).appendChild(it);
    }, updateCatValue);
  });

  document.querySelectorAll('.match-pairs').forEach(function(widget) {
    widget.querySelectorAll('.match-row').forEach(function(row) {
      var dz = row.querySelector('.match-dropzone');
      var left = row.querySelector('.bg-light');
      if (dz) makeButtonLike(dz, 'Para ihop det valda med ' + (left ? left.textContent.trim() : 'raden'));
    });
    var pool = widget.querySelector('.match-pool');
    if (pool) makeButtonLike(pool, 'Lägg tillbaka det valda bland alternativen');
    setupPickAndPlace(widget, '.match-draggable', '.match-dropzone, .match-pool', function(target, it) {
      if (target.classList.contains('match-dropzone')) {
        var existing = target.querySelector('.match-draggable');
        var pool = widget.querySelector('.match-pool');
        if (existing && existing !== it && pool) pool.appendChild(existing);
      }
      target.appendChild(it);
    }, updateMatchValue);
  });

  // Ordna: knappar för att flytta upp/ner på varje rad
  document.querySelectorAll('.order-list').forEach(function(list) {
    function refresh() {
      var items = list.querySelectorAll('li');
      items.forEach(function(li, i) {
        var up = li.querySelector('.order-move-up'), down = li.querySelector('.order-move-down');
        var locked = isLocked(list);
        if (up) up.disabled = locked || i === 0;
        if (down) down.disabled = locked || i === items.length - 1;
      });
    }
    list.querySelectorAll('li').forEach(function(li) {
      var text = (li.querySelector('span') || li).textContent.trim();
      [['up', '↑', 'Flytta upp: '], ['down', '↓', 'Flytta ner: ']].forEach(function(d) {
        var b = document.createElement('button');
        b.type = 'button';
        b.className = 'order-move order-move-' + d[0];
        b.textContent = d[1];
        b.setAttribute('aria-label', d[2] + text);
        b.addEventListener('click', function() {
          if (isLocked(list)) return;
          if (d[0] === 'up' && li.previousElementSibling) list.insertBefore(li, li.previousElementSibling);
          if (d[0] === 'down' && li.nextElementSibling) list.insertBefore(li.nextElementSibling, li);
          updateOrderValue(list);
          refresh();
          b.disabled ? li.querySelector('.order-move:not(:disabled)').focus() : b.focus();
        });
        li.appendChild(b);
      });
    });
    list.addEventListener('dragend', refresh);
    refresh();
  });

  // ============ HOTSPOT (click on image) ============
  document.querySelectorAll('.hotspot-widget').forEach(function(container) {
    var img = container.querySelector('img');
    var marker = container.querySelector('.hotspot-marker');
    var name = container.getAttribute('data-name');
    var xInput = container.parentNode.querySelector('input[name="' + name + '_x"]');
    var yInput = container.parentNode.querySelector('input[name="' + name + '_y"]');
    if (!img || !marker) return;
    img.addEventListener('click', function(e) {
      var rect = img.getBoundingClientRect();
      var x = (e.clientX - rect.left) / rect.width;
      var y = (e.clientY - rect.top) / rect.height;
      marker.style.display = 'block';
      marker.style.left = (x * 100) + '%';
      marker.style.top = (y * 100) + '%';
      if (xInput) xInput.value = x.toFixed(4);
      if (yInput) yInput.value = y.toFixed(4);
    });
  });

  // ============ IMAGE CHOICE visual feedback ============
  document.querySelectorAll('.image-choice-input').forEach(function(inp) {
    inp.addEventListener('change', function() {
      var label = inp.closest('.image-choice-option');
      if (!label) return;
      if (inp.type === 'radio') {
        document.querySelectorAll('input[name="' + inp.name + '"]').forEach(function(other) {
          var otherLabel = other.closest('.image-choice-option');
          if (otherLabel) otherLabel.classList.remove('border-primary', 'bg-primary', 'bg-opacity-10');
        });
      }
      if (inp.checked) label.classList.add('border-primary', 'bg-primary', 'bg-opacity-10');
      else label.classList.remove('border-primary', 'bg-primary', 'bg-opacity-10');
    });
  });

  // ============ PER-QUESTION ANSWER SUBMISSION ============
  // Varje fråga har en "Svara"-knapp. Klick → AJAX-POST med frågans
  // formulärfält → server bedömer just den frågan → visar feedback.
  // När alla frågor är rätt redirectas användaren (eller markeras klar).
  document.addEventListener('click', function(ev) {
    var btn = ev.target.closest('.quiz-answer-btn');
    if (!btn) return;
    ev.preventDefault();

    var questionEl = btn.closest('.quiz-question');
    if (!questionEl) return;
    var qid = btn.getAttribute('data-question-id');
    var csrfInput = document.getElementById('quizCsrfToken');
    var csrfToken = csrfInput ? csrfInput.value : '';

    var fd = new FormData();
    fd.append('csrf_token', csrfToken);
    fd.append('answer_question_id', qid);

    // Samla alla form-kontroller inom frågan. Vi stödjer text-inputs,
    // radio/checkbox (inkl. namn-arrayer som qN_answer[]) och hidden-fält.
    questionEl.querySelectorAll('input, select, textarea').forEach(function(el) {
      if (!el.name) return;
      if ((el.type === 'radio' || el.type === 'checkbox') && !el.checked) return;
      fd.append(el.name, el.value);
    });

    var feedbackEl = questionEl.querySelector('[data-feedback]');
    btn.disabled = true;
    var originalBtnHtml = btn.innerHTML;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span>';
    if (feedbackEl) feedbackEl.innerHTML = '';

    fetch(window.location.pathname + window.location.search, {
      method: 'POST',
      body: fd,
      headers: { 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-Token': csrfToken }
    })
      .then(function(r) { return r.json(); })
      .then(function(data) {
        if (!data.success) {
          showFeedback(feedbackEl, false, data.message || 'Något gick fel.');
          btn.disabled = false;
          btn.innerHTML = originalBtnHtml;
          return;
        }

        // Visa per-frågsfeedback
        showFeedback(feedbackEl, data.correct, data.correct ? 'Rätt!' : 'Fel svar.');

        // Ska frågan låsas? (alltid vid rätt, även vid fel i any_result-läge)
        if (data.lock_question) {
          questionEl.querySelectorAll('input, select, textarea, button').forEach(function(el) {
            if (!el.classList.contains('quiz-answer-btn')) el.disabled = true;
          });
          if (data.correct) {
            questionEl.classList.add('border-success');
            btn.classList.remove('btn-primary');
            btn.classList.add('btn-success');
            btn.innerHTML = '<i class="bi bi-check-lg"></i> Rätt';
          } else {
            questionEl.classList.add('border-danger');
            btn.classList.remove('btn-primary');
            btn.classList.add('btn-danger');
            btn.innerHTML = '<i class="bi bi-x-lg"></i> Fel';
          }
          btn.disabled = true;
        } else {
          // Fel svar i strikt läge → låt deltagaren försöka igen
          btn.disabled = false;
          btn.innerHTML = originalBtnHtml;
        }

        // Uppdatera löpande tally: "3 rätt, 1 fel, 2 kvar"
        var progressEl = document.getElementById('quizProgress');
        if (progressEl && typeof data.total === 'number') {
          var left = data.total - (data.correct_count || 0) - (data.wrong_count || 0);
          progressEl.innerHTML =
            '<i class="bi bi-check-circle text-success me-1"></i>' + (data.correct_count || 0) + ' rätt · ' +
            '<i class="bi bi-x-circle text-danger me-1"></i>' + (data.wrong_count || 0) + ' fel · ' +
            (left > 0 ? left + ' kvar' : 'alla frågor besvarade');
        }

        // Notifiera ev. multipage-paginator att en fråga är bedömd. Den
        // använder kvitto-eventet för att avaktivera/aktivera Next-knappen.
        try {
          document.dispatchEvent(new CustomEvent('quiz-question-graded', {
            detail: {
              questionId: qid,
              correct: !!data.correct,
              passMode: data.pass_mode || 'require_all_correct',
              lockQuestion: !!data.lock_question,
              allDone: !!data.all_done,
              nextLesson: data.nextLesson || null,
              courseComplete: !!data.courseComplete,
              completeUrl: data.completeUrl || null
            }
          }));
        } catch (e) { /* gammal browser utan CustomEvent — ignorera */ }

        // Alla frågor besvarade?
        if (data.all_done) {
          // Hela kursen klar? Skicka direkt till avslutssidan.
          if (data.courseComplete && data.completeUrl) {
            window.location.href = data.completeUrl;
            return;
          }

          var total = data.total || 0;
          var correctN = data.correct_count || 0;
          var wrongN = data.wrong_count || 0;
          var summary = '';
          if (data.pass_mode === 'any_result') {
            summary = '<strong>Quizet är klart!</strong> Du svarade rätt på <strong>' + correctN + ' av ' + total + '</strong> frågor. Dina svar är sparade.';
          } else {
            summary = '<strong>Alla rätt!</strong> Du besvarade <strong>' + total + ' av ' + total + '</strong> frågor korrekt.';
          }

          var nextBtnHtml = '';
          if (data.nextLesson && data.nextLesson.available) {
            nextBtnHtml = '<div class="mt-2"><a href="lesson.php?id=' + data.nextLesson.id + '" class="btn btn-primary"><i class="bi bi-arrow-right me-1"></i>Gå till nästa lektion: ' + escapeHtml(data.nextLesson.title || '') + '</a></div>';
          } else if (data.nextLesson && !data.nextLesson.available) {
            nextBtnHtml = '<div class="small text-muted mt-2"><i class="bi bi-clock me-1"></i>Nästa lektion låses upp senare (stegvis kurs).</div>';
          } else {
            nextBtnHtml = '<div class="mt-2"><a href="index.php" class="btn btn-outline-primary"><i class="bi bi-house me-1"></i>Tillbaka till kursöversikten</a></div>';
          }

          if (progressEl) {
            progressEl.innerHTML = '<div class="alert alert-success mt-2"><i class="bi bi-trophy-fill me-1"></i>' + summary + nextBtnHtml + '</div>';
            progressEl.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
          }
        }
      })
      .catch(function() {
        showFeedback(feedbackEl, false, 'Nätverksfel. Försök igen.');
        btn.disabled = false;
        btn.innerHTML = originalBtnHtml;
      });
  });

  function showFeedback(el, correct, msg) {
    if (!el) return;
    el.innerHTML = '<span class="badge bg-' + (correct ? 'success' : 'danger') + '"><i class="bi bi-' + (correct ? 'check-circle' : 'x-circle') + ' me-1"></i>' + msg + '</span>';
  }

  function escapeHtml(s) {
    return String(s)
      .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
  }

})();
