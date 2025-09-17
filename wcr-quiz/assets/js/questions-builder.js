/**
 * Visual builder for quiz questions in the admin panel.
 */
jQuery(function ($) {
  const container = $('#wcrq-questions-builder');
  const input = $('#wcrq_questions_input');
  const fallback = $('.wcrq-questions-fallback');

  if (!container.length || !input.length) {
    return;
  }

  const form = container.closest('form');
  let mediaFrame = null;
  let counter = 0;
  const hasI18n = typeof window !== 'undefined' && window.wp && window.wp.i18n && typeof window.wp.i18n.__ === 'function';

  function __(text) {
    return hasI18n ? window.wp.i18n.__(text, 'wcrq') : text;
  }

  fallback.hide();

  function ensureAnswersArray(value) {
    const answers = Array.isArray(value) ? value.slice(0, 4) : [];
    while (answers.length < 4) {
      answers.push('');
    }
    return answers;
  }

  function escapeHtml(value) {
    return $('<div>').text(value || '').html();
  }

  function nextKey() {
    const key = counter;
    counter += 1;
    return key;
  }

  function getQuestionData(wrapper) {
    const answers = [];
    wrapper.find('.wcrq-a').each(function () {
      answers.push($(this).val());
    });
    return {
      key: wrapper.data('key'),
      question: wrapper.find('.wcrq-q').val(),
      image: wrapper.find('.wcrq-img').val(),
      answers,
      correct: parseInt(wrapper.find('input[type=radio]:checked').val(), 10) || 0,
    };
  }

  function collect() {
    const collected = [];
    container.children('.wcrq-question').each(function () {
      const question = getQuestionData($(this));
      collected.push({
        question: question.question,
        answers: ensureAnswersArray(question.answers),
        correct: question.correct,
        image: question.image,
      });
    });
    input.val(JSON.stringify(collected));
  }

  function snapshotQuestions() {
    const questions = [];
    container.children('.wcrq-question').each(function () {
      questions.push(getQuestionData($(this)));
    });
    return questions;
  }

  function renderQuestion(question) {
    const key = typeof question.key === 'number' ? question.key : nextKey();
    const answers = ensureAnswersArray(question.answers);

    const wrapper = $('<div class="wcrq-question">').attr('data-key', key);
    const headingNumber = container.children('.wcrq-question').length + 1;
    const heading = $('<h3 class="wcrq-question-heading">').text(`${__('Pytanie')} ${headingNumber}`);
    wrapper.append(heading);

    const questionRow = $('<p class="wcrq-question-row">');
    const questionLabel = $('<label class="wcrq-field-label">');
    questionLabel.append($('<span>').text(__('Treść pytania')));
    const questionInput = $('<input type="text" class="wcrq-q regular-text">').val(question.question || '');
    questionLabel.append(': ').append(questionInput);
    questionRow.append(questionLabel);
    wrapper.append(questionRow);

    const imageHolder = $('<div class="wcrq-image">');
    if (question.image) {
      imageHolder.append(
        $('<img>', {
          src: question.image,
          alt: '',
          style: 'max-width:150px;height:auto;',
        }),
      );
    }
    const imageButtons = $('<p class="wcrq-image-actions">');
    const selectButton = $('<button type="button" class="button wcrq-select-image">').text(__('Wybierz grafikę'));
    const removeButton = $('<button type="button" class="button wcrq-remove-image">').text(__('Usuń grafikę'));
    imageButtons.append(selectButton).append(' ').append(removeButton);
    const imageInput = $('<input type="hidden" class="wcrq-img">').val(question.image || '');
    imageHolder.append(imageButtons).append(imageInput);
    wrapper.append(imageHolder);

    const answersWrapper = $('<div class="wcrq-answers">');
    answersWrapper.append('<p><strong>' + __('Odpowiedzi') + '</strong></p>');
    answers.forEach(function (answer, idx) {
      const radioName = 'wcrq_correct_' + key;
      const checked = parseInt(question.correct, 10) === idx ? 'checked' : '';
      const label = $('<label class="wcrq-answer-row">');
      const radio = $('<input type="radio">').attr({
        name: radioName,
        value: idx,
      });
      if (checked) {
        radio.prop('checked', true);
      }
      const answerInput = $('<input type="text" class="wcrq-a regular-text">').val(answer || '');
      label.append(radio).append(' ').append(answerInput);
      answersWrapper.append($('<p>').append(label));
    });
    wrapper.append(answersWrapper);

    wrapper.append(
      '<p class="wcrq-question-actions"><button type="button" class="button wcrq-preview">' +
        __('Podgląd') +
        '</button> <button type="button" class="button button-link-delete wcrq-remove">' +
        __('Usuń pytanie') +
        '</button></p><div class="wcrq-preview-area" style="display:none;"></div>',
    );

    container.append(wrapper);
  }

  function render(existingQuestions) {
    container.empty();
    existingQuestions.forEach(function (question) {
      renderQuestion(question);
    });
    collect();
  }

  container.on('click', '.wcrq-remove', function () {
    $(this).closest('.wcrq-question').remove();
    render(snapshotQuestions());
  });

  container.on('input change', '.wcrq-q, .wcrq-a', collect);
  container.on('change', 'input[type=radio]', collect);

  container.on('click', '.wcrq-select-image', function (event) {
    event.preventDefault();
    const holder = $(this).closest('.wcrq-image');

    if (mediaFrame) {
      mediaFrame.close();
    }

    mediaFrame = wp.media({
      title: __('Wybierz grafikę'),
      button: { text: __('Użyj') },
      multiple: false,
    });

    mediaFrame.on('select', function () {
      const attachment = mediaFrame.state().get('selection').first().toJSON();
      holder.find('.wcrq-img').val(attachment.url);
      holder.find('img').remove();
      holder.prepend(
        $('<img>', {
          src: attachment.url,
          alt: '',
          style: 'max-width:150px;height:auto;',
        }),
      );
      collect();
    });

    mediaFrame.open();
  });

  container.on('click', '.wcrq-remove-image', function (event) {
    event.preventDefault();
    const holder = $(this).closest('.wcrq-image');
    holder.find('.wcrq-img').val('');
    holder.find('img').remove();
    collect();
  });

  container.on('click', '.wcrq-preview', function () {
    const wrap = $(this).closest('.wcrq-question');
    const data = getQuestionData(wrap);
    const preview = wrap.find('.wcrq-preview-area');

    let html = '';
    if (data.question) {
      html += '<p><strong>' + escapeHtml(data.question) + '</strong></p>';
    }
    if (data.image) {
      html += '<p><img src="' + escapeHtml(data.image) + '" style="max-width:150px;height:auto;" alt="" /></p>';
    }
    data.answers.forEach(function (answer, index) {
      if (!answer) {
        return;
      }
      const mark = data.correct === index ? ' <em>(' + __('prawidłowa') + ')</em>' : '';
      html += '<p><label><input type="radio" disabled> ' + escapeHtml(answer) + mark + '</label></p>';
    });

    preview.html(html || '<p>' + __('Brak danych do podglądu.') + '</p>');
    preview.toggle();
  });

  if (form.length) {
    form.on('submit', collect);
  }

  let existing = [];
  try {
    existing = JSON.parse(input.val());
  } catch (error) {
    existing = [];
  }

  if (!Array.isArray(existing)) {
    existing = [];
  }

  existing = existing.map(function (question) {
    question.key = nextKey();
    question.answers = ensureAnswersArray(question.answers);
    question.correct = typeof question.correct === 'number' ? question.correct : 0;
    return question;
  });

  render(existing);

  const addButton = $('<p><button type="button" class="button" id="wcrq_add_question"></button></p>');
  addButton.find('button').text(__('Dodaj pytanie'));
  container.after(addButton);

  $(document).on('click', '#wcrq_add_question', function (event) {
    event.preventDefault();
    const questions = snapshotQuestions();
    questions.push({
      key: nextKey(),
      question: '',
      answers: ['', '', '', ''],
      correct: 0,
      image: '',
    });
    render(questions);
  });
});

