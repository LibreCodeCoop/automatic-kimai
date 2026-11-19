<section><h2>Informar horas manualmente</h2>
<p class="muted">Registre horas trabalhadas, inclusive reuniões e atividades presenciais. Informe os horários reais. Caso tenha feito uma pausa, use o campo opcional para descontá-la. O faturamento dos clientes é tratado separadamente no sistema financeiro.</p>
<form method="post" action="{{route('kimai.manual')}}">@csrf
<div class="grid">
<div><label>Data (AAAA-MM-DD)</label><input type="date" name="day" required></div>
<div><label>Início real</label><input type="time" name="start" required></div>
<div><label>Fim real</label><input type="time" name="end" required></div>
<div><label>Descrição curta da atividade</label><input name="task" maxlength="64" placeholder="Revisão de documentos" required></div>
</div>
<details class="advanced-settings">
<summary>Informar pausa (opcional)</summary>
<p class="muted">Se houve uma pausa, informe início e fim. O sistema registra apenas os intervalos efetivamente trabalhados, sem contar a pausa.</p>
<div class="grid">
<div><label for="manual-pause-start">Início da pausa</label><input id="manual-pause-start" type="time" name="pause_start"></div>
<div><label for="manual-pause-end">Fim da pausa</label><input id="manual-pause-end" type="time" name="pause_end"></div>
</div>
</details>
<label><input type="checkbox" name="confirmed" value="1"> Confirmo que realizei pessoalmente o trabalho, excluindo pausas e execução autônoma da IA.</label>
<button type="submit">Registrar horas no Kimai</button>
</form>
</section>
