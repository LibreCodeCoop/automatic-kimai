<section><h2>Informar horas manualmente</h2>
<p class="muted">Registre horas que você trabalhou e que não foram identificadas automaticamente. Confirme os horários reais e desconte as pausas. O faturamento dos clientes é tratado separadamente no sistema financeiro.</p>
<form method="post" action="{{route('kimai.manual')}}">@csrf
<div class="grid">
<div><label>Data (AAAA-MM-DD)</label><input type="date" name="day" required></div>
<div><label>Início real</label><input type="time" name="start" required></div>
<div><label>Fim real</label><input type="time" name="end" required></div>
<div><label>Descrição curta da atividade</label><input name="task" maxlength="64" placeholder="Revisão de documentos" required></div>
</div>
<label><input type="checkbox" name="confirmed" value="1"> Confirmo que realizei pessoalmente o trabalho, excluindo pausas e execução autônoma da IA.</label>
<button type="submit">Registrar horas no Kimai</button>
</form>
</section>
