<section><h2>Informar horas manualmente</h2>
<p class="muted">Aqui você poderá declarar horários efetivos de trabalho, sempre descontando pausas. O envio pelo PHP fica indisponível até a migração segura das aprovações e dos registros já existentes.</p>
<form method="post" action="{{route('kimai.manual')}}">@csrf
<div class="grid">
<div><label>Data (AAAA-MM-DD)</label><input type="date" name="day" required></div>
<div><label>Início real</label><input type="time" name="start" required></div>
<div><label>Fim real</label><input type="time" name="end" required></div>
<div><label>Descrição curta da atividade</label><input name="task" maxlength="64" placeholder="Revisão de documentos" required></div>
</div>
<label><input type="checkbox" name="confirmed" value="1"> Confirmo que realizei pessoalmente o trabalho, excluindo pausas e execução autônoma da IA.</label>
<button type="submit" disabled title="Disponível somente após migração segura de ledger e serviços">Registrar (aguardando homologação)</button>
</form>
</section>
