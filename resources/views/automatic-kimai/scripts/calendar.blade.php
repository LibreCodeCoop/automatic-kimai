(() => {
  const dialog=document.getElementById('calendar-detail-dialog');
  if(!dialog)return;
  const source={registered:'Lançamento confirmado no Kimai',observed:'Atividade observada; duração de trabalho não comprovada',scheduled:'Agenda; presença não comprovada'};
  document.querySelectorAll('.calendar-event').forEach(button=>{
    button.addEventListener('click',()=>{
      document.getElementById('calendar-detail-title').textContent=button.dataset.calendarTitle||'Atividade';
      document.getElementById('calendar-detail-time').textContent=button.dataset.calendarTime||'';
      document.getElementById('calendar-detail-source').textContent=source[button.dataset.calendarType]||'';
      document.getElementById('calendar-detail-description').textContent=button.dataset.calendarDetails||'Sem descrição adicional.';
      dialog.showModal();
    });
  });
})();