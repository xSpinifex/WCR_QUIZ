jQuery(function($){
  const container = $('#wcrq-questions-builder');
  const input = $('#wcrq_questions_input');
  const fallback = $('.wcrq-questions-fallback');

  if(!container.length || !input.length){
    return;
  }

  fallback.hide();

  function addQuestion(q){
    const index = container.children('.wcrq-question').length;
    const div = $('<div class="wcrq-question">');

    // Question text
    div.append('<p><label>Pytanie: <input type="text" class="wcrq-q" value="'+(q.question||'')+'"></label></p>');

    // Image picker
    const imgUrl = q.image || '';
    const imgTag = imgUrl ? '<img src="'+imgUrl+'" style="max-width:150px;height:auto;" />' : '';
    div.append('<div class="wcrq-image">'+imgTag+'<p><button type="button" class="button wcrq-select-image">Wybierz grafikę</button> <button type="button" class="button wcrq-remove-image">Usuń</button></p><input type="hidden" class="wcrq-img" value="'+imgUrl+'"></div>');

    // Answers
    const answersDiv = $('<div class="wcrq-answers"><p>Odpowiedzi:</p></div>');
    for(let i=0;i<4;i++){
      const ans = q.answers && q.answers[i] ? q.answers[i] : '';
      const checked = q.correct===i? 'checked' : '';
      answersDiv.append('<p><label><input type="radio" name="correct'+index+'" value="'+i+'" '+checked+'> <input type="text" class="wcrq-a" value="'+ans+'"></label></p>');
    }
    div.append(answersDiv);

    // Action buttons
    div.append('<p><button type="button" class="button wcrq-preview">Podgląd</button> <button type="button" class="button wcrq-remove">Usuń</button></p><div class="wcrq-preview-area" style="display:none;"></div>');

    container.append(div);
  }

  function collect(){
    const arr = [];
    container.children('.wcrq-question').each(function(){
      const qText = $(this).find('.wcrq-q').val();
      const answers = [];
      $(this).find('.wcrq-a').each(function(){ answers.push($(this).val()); });
      const correct = parseInt($(this).find('input[type=radio]:checked').val()) || 0;
      const img = $(this).find('.wcrq-img').val();
      arr.push({question:qText, answers:answers, correct:correct, image:img});
    });
    input.val(JSON.stringify(arr));
  }

  // Remove question
  container.on('click', '.wcrq-remove', function(){ $(this).closest('.wcrq-question').remove(); });

  // Select image using WP media library
  let frame;
  container.on('click', '.wcrq-select-image', function(e){
    e.preventDefault();
    const holder = $(this).closest('.wcrq-image');
    if(frame){ frame.close(); }
    frame = wp.media({
      title: 'Wybierz grafikę',
      button: { text: 'Użyj' },
      multiple: false
    });
    frame.on('select', function(){
      const attachment = frame.state().get('selection').first().toJSON();
      holder.find('.wcrq-img').val(attachment.url);
      holder.find('img').remove();
      holder.prepend('<img src="'+attachment.url+'" style="max-width:150px;height:auto;" />');
    });
    frame.open();
  });

  // Remove image
  container.on('click', '.wcrq-remove-image', function(){
    const holder = $(this).closest('.wcrq-image');
    holder.find('.wcrq-img').val('');
    holder.find('img').remove();
  });

  // Preview question
  container.on('click', '.wcrq-preview', function(){
    const wrap = $(this).closest('.wcrq-question');
    const qText = wrap.find('.wcrq-q').val();
    const img = wrap.find('.wcrq-img').val();
    const answers = [];
    wrap.find('.wcrq-a').each(function(){ answers.push($(this).val()); });
    let html = '<p>'+qText+'</p>';
    if(img){ html += '<p><img src="'+img+'" style="max-width:150px;height:auto;" /></p>'; }
    answers.forEach(function(a){ html += '<label><input type="radio" disabled> '+a+'</label><br />'; });
    const prev = wrap.find('.wcrq-preview-area');
    prev.html(html).toggle();
  });

  // Collect data on form submit
  $('form').on('submit', collect);

  // Load existing questions
  let existing = [];
  try{ existing = JSON.parse(input.val()); }catch(e){}
  if(Array.isArray(existing)) existing.forEach(addQuestion);

  // Add button after container and bind handler
  container.after('<p><button type="button" class="button" id="wcrq_add_question">Dodaj pytanie</button></p>');
  $(document).on('click', '#wcrq_add_question', function(){ addQuestion({}); });
});

