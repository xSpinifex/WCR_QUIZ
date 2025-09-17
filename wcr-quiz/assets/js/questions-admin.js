(function () {
    'use strict';

    function ready(fn) {
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', fn);
        } else {
            fn();
        }
    }

    ready(function () {
        var form = document.getElementById('wcrq-questions-form');
        if (!form) {
            return;
        }

        var list = form.querySelector('.wcrq-question-list');
        var addButton = document.getElementById('wcrq-add-question');
        var template = document.getElementById('wcrq-question-template');
        if (!list || !template) {
            return;
        }

        var strings = window.wcrqQuestionsAdmin || {};
        var mediaFrame = null;
        var currentImageField = null;

        var questionNamePattern = /wcrq_settings\[questions]\[(?:__index__|\d+)\]/;
        var questionIdPattern = /wcrq_questions_(?:__index__|\d+)/g;

        function updatePreview(input) {
            if (!input) {
                return;
            }
            var item = input.closest('.wcrq-question-item');
            if (!item) {
                return;
            }
            var preview = item.querySelector('.wcrq-image-preview');
            if (!preview) {
                return;
            }
            preview.textContent = '';
            var url = input.value.trim();
            if (!url) {
                return;
            }
            var img = document.createElement('img');
            img.src = url;
            img.alt = '';
            preview.appendChild(img);
        }

        function refreshPreviews() {
            list.querySelectorAll('input[name^="wcrq_settings"][name$="[image]"]').forEach(function (input) {
                updatePreview(input);
            });
        }

        function toggleRemoveState() {
            var items = list.querySelectorAll('.wcrq-question-item');
            var disable = items.length <= 1;
            items.forEach(function (item) {
                var button = item.querySelector('.wcrq-remove-question');
                if (button) {
                    button.disabled = disable;
                }
            });
        }

        function updateOrder() {
            var items = list.querySelectorAll('.wcrq-question-item');
            items.forEach(function (item, index) {
                item.dataset.index = String(index);
                var number = item.querySelector('.wcrq-question-number');
                if (number) {
                    number.textContent = String(index + 1);
                }

                item.querySelectorAll('[name]').forEach(function (field) {
                    var name = field.getAttribute('name');
                    if (!name || !questionNamePattern.test(name)) {
                        return;
                    }
                    field.setAttribute(
                        'name',
                        name.replace(questionNamePattern, 'wcrq_settings[questions][' + index + ']')
                    );
                });

                item.querySelectorAll('[id]').forEach(function (field) {
                    if (!field.id) {
                        return;
                    }
                    field.id = field.id.replace(questionIdPattern, 'wcrq_questions_' + index);
                });

                item.querySelectorAll('label[for]').forEach(function (label) {
                    var forId = label.getAttribute('for');
                    if (!forId) {
                        return;
                    }
                    label.setAttribute('for', forId.replace(questionIdPattern, 'wcrq_questions_' + index));
                });
            });
            toggleRemoveState();
        }

        function createQuestion() {
            var index = list.querySelectorAll('.wcrq-question-item').length;
            var wrapper = document.createElement('div');
            wrapper.innerHTML = template.innerHTML.replace(/__index__/g, index);
            var element = wrapper.firstElementChild;
            if (!element) {
                return;
            }
            list.appendChild(element);
            updateOrder();
            refreshPreviews();
            var focusTarget = element.querySelector('textarea');
            if (focusTarget) {
                focusTarget.focus();
            }
        }

        function removeQuestion(item) {
            var items = list.querySelectorAll('.wcrq-question-item');
            if (items.length <= 1) {
                window.alert(strings.minimumNotice || 'Musisz zachować co najmniej jedno pytanie.');
                return;
            }
            if (strings.removeConfirmation && !window.confirm(strings.removeConfirmation)) {
                return;
            }
            item.remove();
            updateOrder();
            refreshPreviews();
        }

        function openMediaLibrary(item) {
            if (typeof wp === 'undefined' || !wp.media) {
                return;
            }
            currentImageField = item.querySelector('input[name^="wcrq_settings"][name$="[image]"]');
            if (!currentImageField) {
                return;
            }
            if (!mediaFrame) {
                mediaFrame = wp.media({
                    title: strings.chooseImage || 'Wybierz obrazek',
                    button: { text: strings.chooseImage || 'Wybierz obrazek' },
                    library: { type: 'image' },
                    multiple: false,
                });
                mediaFrame.on('select', function () {
                    var attachment = mediaFrame.state().get('selection').first();
                    if (!attachment || !currentImageField) {
                        return;
                    }
                    var url = attachment.get('url');
                    currentImageField.value = url || '';
                    updatePreview(currentImageField);
                });
            }
            mediaFrame.open();
        }

        if (addButton) {
            addButton.addEventListener('click', function (event) {
                event.preventDefault();
                createQuestion();
            });
        }

        list.addEventListener('click', function (event) {
            var removeButton = event.target.closest('.wcrq-remove-question');
            if (removeButton) {
                event.preventDefault();
                var item = removeButton.closest('.wcrq-question-item');
                if (item) {
                    removeQuestion(item);
                }
                return;
            }

            var selectButton = event.target.closest('.wcrq-select-image');
            if (selectButton) {
                event.preventDefault();
                var itemForImage = selectButton.closest('.wcrq-question-item');
                if (itemForImage) {
                    openMediaLibrary(itemForImage);
                }
                return;
            }

            var clearButton = event.target.closest('.wcrq-clear-image');
            if (clearButton) {
                event.preventDefault();
                var itemForClear = clearButton.closest('.wcrq-question-item');
                if (!itemForClear) {
                    return;
                }
                var input = itemForClear.querySelector('input[name^="wcrq_settings"][name$="[image]"]');
                if (input) {
                    input.value = '';
                    updatePreview(input);
                }
            }
        });

        list.addEventListener('input', function (event) {
            var target = event.target;
            if (target && target.matches('input[name^="wcrq_settings"][name$="[image]"]')) {
                updatePreview(target);
            }
        });

        updateOrder();
        refreshPreviews();
    });
})();
