document.addEventListener('DOMContentLoaded', () => {
  const form = document.querySelector('form.wcrq-registration');
  if (!form) return;
  form.addEventListener('submit', (e) => {
    const inputs = ['wcrq_school', 'wcrq_gmina', 'wcrq_name', 'wcrq_class', 'wcrq_email']
      .map(name => form.querySelector(`[name="${name}"]`));
    const pattern = /<|>|script|select|insert|delete|update|drop|union|--/i;
    for (const input of inputs) {
      if (pattern.test(input.value)) {
        e.preventDefault();
        alert('nie mozna psuć');
        return false;
      }
    }
  });
});
