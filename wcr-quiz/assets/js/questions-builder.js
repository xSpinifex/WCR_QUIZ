/**
 * Admin builder for quiz questions.
 */
jQuery(function ($) {
  var app = $('#wcrq-questions-app');
  if (!app.length) {
    return;
  }

  var list = app.find('.wcrq-question-list');
  var fallback = $('.wcrq-questions-fallback');
  var addButton = $('#wcrq_add_question');
  var templateElement = $('#wcrq-question-template');
  var templateHtml = templateElement.length ? templateElement.html().trim() : '';
  var form = app.closest('form');
  var hasI18n =
    typeof window !== 'undefined' &&
    window.wp &&
    window.wp.i18n &&
    typeof window.wp.i18n.__ === 'function';

  function __(text) {
    return hasI18n ? window.wp.i18n.__(text, 'wcrq') : text;
  }

  if (!templateHtml) {
    return;
  }

  if (fallback.length) {
    fallback.hide();
  }

  var mediaFrame = null;

  function escapeHtml(value) {
    return $('<div>').text(value || '').html();
  }

  function getBlankQuestion() {
    return {
      question: '',
      answers: ['', '', '', ''],
      correct: 0,
      image: ''
    };
  }

  function ensureAnswers(values) {
    var answers = Array.isArray(values) ? values.slice(0, 4) : [];
    while (answers.length < 4) {
      answers.push('');
    }
    return answers;
  }

  function updateRemoveState() {
    var items = list.children('.wcrq-question-item');
    var disableRemove = items.length <= 1;
    items.each(function () {
      $(this)
        .find('.wcrq-remove')
        .prop('disabled', disableRemove);
    });
  }

  function refreshIndices() {
    list.children('.wcrq-question-item').each(function (index) {
      var item = $(this);
      item.attr('data-index', index);
      item.find('.wcrq-question-number').text(index + 1);
      item.find('[data-name-template]').each(function () {
        var field = $(this);
        var templateName = field.data('nameTemplate');
        if (templateName) {
          field.attr('name', templateName.replace(/__index__/g, index));
        }
      });
    });
    updateRemoveState();
  }

  function setQuestionValues(item, data) {
    var question = data || getBlankQuestion();
    var answers = ensureAnswers(question.answers);

    item.find('.wcrq-q').val(question.question || '');
    item.find('.wcrq-img').val(question.image || '');

    var preview = item.find('.wcrq-image-preview');
    preview.empty();
    if (question.image) {
      preview.append(
        $('<img>', {
          src: question.image,
          alt: ''
        })
      );
    }

    item.find('.wcrq-a').each(function (idx) {
      $(this).val(answers[idx] || '');
    });

    var correct =
      typeof question.correct === 'number'
        ? question.correct
        : parseInt(question.correct, 10) || 0;
    item.find('.wcrq-correct').each(function () {
      var radio = $(this);
      radio.prop('checked', parseInt(radio.val(), 10) === correct);
    });
  }

  function createQuestion(data) {
    var element = $(templateHtml);
    element.removeClass('wcrq-question-item-template');
    setQuestionValues(element, data);
    list.append(element);
    refreshIndices();
    return element;
  }

  function getQuestionData(wrapper) {
    var answers = [];
    wrapper.find('.wcrq-a').each(function () {
      answers.push($(this).val());
    });
    return {
      question: wrapper.find('.wcrq-q').val(),
      image: wrapper.find('.wcrq-img').val(),
      answers: answers,
      correct: parseInt(wrapper.find('.wcrq-correct:checked').val(), 10) || 0
    };
  }

  function clearQuestion(wrapper) {
    setQuestionValues(wrapper, getBlankQuestion());
    wrapper.find('.wcrq-preview-area').hide().empty();
  }

  function renderPreview(wrapper) {
    var data = getQuestionData(wrapper);
    var preview = wrapper.find('.wcrq-preview-area');
    var html = '';

    if (data.question) {
      html += '<p><strong>' + escapeHtml(data.question) + '</strong></p>';
    }

    if (data.image) {
      html +=
        '<p><img src="' +
        escapeHtml(data.image) +
        '" alt="" style="max-width:150px;height:auto;" /></p>';
    }

    data.answers.forEach(function (answer, index) {
      if (!answer) {
        return;
      }
      var mark = data.correct === index ? ' <em>(' + __('prawidłowa') + ')</em>' : '';
      html +=
        '<p><label><input type="radio" disabled> ' +
        escapeHtml(answer) +
        mark +
        '</label></p>';
    });

    preview.html(html || '<p>' + __('Brak danych do podglądu.') + '</p>');
    preview.toggle();
  }

  addButton.on('click', function (event) {
    event.preventDefault();
    var element = createQuestion(getBlankQuestion());
    element.find('.wcrq-q').trigger('focus');
  });

  list.on('click', '.wcrq-remove', function (event) {
    event.preventDefault();
    var wrapper = $(this).closest('.wcrq-question-item');
    var items = list.children('.wcrq-question-item');
    if (items.length <= 1) {
      clearQuestion(wrapper);
      return;
    }
    wrapper.remove();
    refreshIndices();
  });

  list.on('click', '.wcrq-preview', function (event) {
    event.preventDefault();
    renderPreview($(this).closest('.wcrq-question-item'));
  });

  list.on('input change', '.wcrq-q, .wcrq-a, .wcrq-correct', function () {
    $(this).closest('.wcrq-question-item').find('.wcrq-preview-area').hide();
  });

  list.on('change', '.wcrq-img', function () {
    var wrapper = $(this).closest('.wcrq-question-item');
    var url = $(this).val();
    var preview = wrapper.find('.wcrq-image-preview');
    preview.empty();
    if (url) {
      preview.append(
        $('<img>', {
          src: url,
          alt: ''
        })
      );
    }
    wrapper.find('.wcrq-preview-area').hide();
  });

  list.on('click', '.wcrq-remove-image', function (event) {
    event.preventDefault();
    var wrapper = $(this).closest('.wcrq-question-item');
    wrapper.find('.wcrq-img').val('');
    wrapper.find('.wcrq-image-preview').empty();
    wrapper.find('.wcrq-preview-area').hide();
  });

  list.on('click', '.wcrq-select-image', function (event) {
    event.preventDefault();
    var wrapper = $(this).closest('.wcrq-question-item');

    if (mediaFrame) {
      mediaFrame.close();
    }

    mediaFrame = wp.media({
      title: __('Wybierz grafikę'),
      button: { text: __('Użyj') },
      multiple: false
    });

    mediaFrame.on('select', function () {
      var attachment = mediaFrame.state().get('selection').first().toJSON();
      wrapper.find('.wcrq-img').val(attachment.url);
      var preview = wrapper.find('.wcrq-image-preview');
      preview.empty().append(
        $('<img>', {
          src: attachment.url,
          alt: ''
        })
      );
      wrapper.find('.wcrq-preview-area').hide();
    });

    mediaFrame.open();
  });

  if (!list.children('.wcrq-question-item').length) {
    createQuestion(getBlankQuestion());
  } else {
    refreshIndices();
  }

  if (form.length) {
    form.on('submit', function () {
      list.find('.wcrq-preview-area').hide();
    });
  }
});
