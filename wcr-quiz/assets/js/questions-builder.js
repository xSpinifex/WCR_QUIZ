jQuery(function($){
  const container = $('#wcrq-questions-builder');
  const input = $('#wcrq_questions_input');
  function addQuestion(q){
    const index = container.children('.wcrq-question').length;
    const div = $('<div class="wcrq-question">');
    div.append('<p><label>Pytanie: <input type="text" class="wcrq-q" value="'+(q.question||'')+'"></label></p>');
    const answersDiv = $('<div class="wcrq-answers"><p>Odpowiedzi:</p></div>');
    for(let i=0;i<4;i++){
      const ans = q.answers && q.answers[i] ? q.answers[i] : '';
      const checked = q.correct===i? 'checked' : '';
      answersDiv.append('<p><label><input type="radio" name="correct'+index+'" value="'+i+'" '+checked+'> <input type="text" class="wcrq-a" value="'+ans+'"></label></p>');
    }
    div.append(answersDiv);
    div.append('<p><button type="button" class="button wcrq-remove">Usuń</button></p>');
    container.append(div);
  }
  function collect(){
    const arr = [];
    container.children('.wcrq-question').each(function(){
      const qText = $(this).find('.wcrq-q').val();
      const answers = [];
      $(this).find('.wcrq-a').each(function(){ answers.push($(this).val()); });
      const correct = parseInt($(this).find('input[type=radio]:checked').val()) || 0;
      arr.push({question:qText, answers:answers, correct:correct});
    });
    input.val(JSON.stringify(arr));
  }
  $('#wcrq_add_question').on('click', function(){ addQuestion({}); });
  container.on('click', '.wcrq-remove', function(){ $(this).closest('.wcrq-question').remove(); });
  $('form').on('submit', collect);
  let existing = [];
  try{ existing = JSON.parse(input.val()); }catch(e){}
  if(Array.isArray(existing)) existing.forEach(addQuestion);
  container.after('<p><button type="button" class="button" id="wcrq_add_question">Dodaj pytanie</button></p>');
});
