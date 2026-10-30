// Interface exclusivamente administrativa; nenhuma credencial vai para recibos ou atributos DOM.
export function dangerZoneHtml(nftPanel){return `<section class="admin-danger-zone" id="admin-danger-zone"><h2>Danger Zone — Ações administrativas sensíveis</h2><p>Sanções e recuperação de acesso para a turma. Toda alteração exige sua senha, motivo e confirmação.</p><form data-danger-form="lookup" class="admin-wallet-search"><label>ID do participante<input name="user_id" inputmode="numeric" pattern="[1-9][0-9]*" required></label><button class="secondary">Consultar participante</button></form><p class="muted">Use a busca de nome ou e-mail em “Participantes e carteiras” e confira o ID antes de agir.</p><div id="danger-participant" aria-live="polite"></div>${nftPanel}</section>`;}

export function bindDanger(ctx){
 const{state,api,esc,amount,date,modal,toast,dialog,dialogContent,errorIn,loadAdmin,loadAdminNft}=ctx;
 let selected=null;
 async function select(id,offset=0){
  if(state.user?.role!=='admin'||state.view!=='admin')return;
  const d=await api(`admin.danger&user_id=${encodeURIComponent(id)}&offset=${offset}`);
  if(state.user?.role!=='admin'||state.view!=='admin')return;
  selected=d;const u=d.u,closed=Boolean(u.deleted_at),banned=u.banned_until&&new Date(u.banned_until.replace(' ','T')+'Z')>new Date();
  const target=document.querySelector('#danger-participant');if(!target)return;
  target.innerHTML=`<div class="danger-subject"><h3>${esc(u.display_name)} · #${esc(u.id)}</h3><p>${esc(u.email)} · ${closed?'Participação encerrada definitivamente':banned?'Banido até '+date(u.banned_until):'Sem restrição ativa'}</p><p>Disponível: ${amount(d.balances.MR.available)} · Reservado: ${amount(d.balances.MR.reserved)}</p>${closed?'':`<div class="danger-actions"><button class="secondary" data-danger-action="fine">Multar em mR$</button><button class="secondary" data-danger-action="${banned?'unban':'ban'}">${banned?'Encerrar banimento':'Banir temporariamente'}</button><button class="secondary" data-danger-action="password">Redefinir senha</button><button class="secondary" data-danger-action="delete">Excluir definitivamente</button></div>`}<h4>Fundo de Custódia para Avaliação · origem #${esc(u.id)}</h4>${d.custody.map(a=>`<p>${amount(a.balance,a.asset)}</p>`).join('')||'<p>Sem saldo em custódia.</p>'}<p>Direitos pendentes: ${amount(d.pending.rights_amount)} · Taxas pendentes: ${amount(d.pending.fees_amount)}</p><p>Direitos e taxas mantêm as regras atuais, sem recebimento ou pagamento automático.</p><p>${d.nfts_total} NFTs em custódia.</p>${d.nfts.map(n=>`<p>NFT #${esc(n.nft_id)} · ${esc(n.title)}</p>`).join('')}<div class="danger-actions"><button class="secondary" data-danger-page="${Math.max(0,d.offset-100)}" ${d.offset===0?'disabled':''}>Anteriores</button><button class="secondary" data-danger-page="${d.offset+100}" ${d.has_more?'':'disabled'}>Próximas NFTs</button></div><h4>Histórico administrativo recente</h4>${d.audit.map(a=>`<details><summary>#${esc(a.id)} · ${esc(a.kind)} · ${date(a.created_at)} · Admin #${esc(a.actor_id)}</summary><pre>${esc(a.data)}</pre></details>`).join('')||'<p>Sem sanções ou redefinições registradas.</p>'}</div>`;
  document.querySelector('[data-danger-form="lookup"] [name=user_id]').value=u.id;
  target.scrollIntoView({block:'nearest'});
 }
 function open(action){
  if(!selected||state.busy||state.unknown){toast('Conclua ou consulte a operação pendente.');return;}
  const u=selected.u;let extra='';
  const titles={fine:'Multar participante',ban:'Banir temporariamente',unban:'Encerrar banimento',delete:'Excluir definitivamente',password:'Redefinir senha do participante'};
  const descriptions={fine:'A multa usa somente mR$ disponível e é transferida à conta de multas. Não usa reservas nem cria dívida.',ban:'Sessões serão encerradas e negócios e anúncios abertos serão cancelados, liberando as reservas das duas partes. O patrimônio é preservado.',unban:'Encerra a restrição antes do prazo. Não aprova cadastro nem verifica e-mail.',delete:'A participação será encerrada sem reativação neste painel. Negócios abertos serão cancelados. Ativos próprios vão ao fundo; NFTs na Piscina ou de terceiros permanecem onde estão.',password:'A nova senha substitui a atual e encerra sessões e links de recuperação anteriores. Não remove banimento nem aprova cadastro.'};
  if(action==='fine')extra=`<p>Disponível: ${amount(selected.balances.MR.available)} · Reservado: ${amount(selected.balances.MR.reserved)}</p><label>Multa em mR$ (use ponto decimal)<input name="amount" inputmode="decimal" pattern="[0-9]+([.][0-9]{1,2})?" required></label><p data-fine-preview>Informe o valor para conferir o saldo após a multa.</p><p>Destino: conta técnica de multas, separada do fundo de custódia.</p>`;
  if(action==='ban')extra='<label>Duração em minutos<input name="minutes" type="number" min="1" max="525600" value="60" required></label><p data-ban-preview></p>';
  if(action==='delete')extra=`<p>Prévia: ${amount(selected.balances.MR.available)}, ${amount(selected.balances.BYC.available,'BYC')} e ${amount(selected.balances.SHARE.available,'SHARE')} disponíveis; reservas serão liberadas antes da custódia. Direitos e taxas ficam pendentes.</p><label>Digite EXCLUIR ${esc(u.id)}<input name="confirmation" required autocomplete="off"></label>`;
  if(action==='password')extra='<label>Nova senha do participante (12 a 72 bytes)<input name="new_password" type="password" autocomplete="new-password" required minlength="12" maxlength="72"></label><label>Confirme a nova senha<input name="new_password_confirmation" type="password" autocomplete="new-password" required minlength="12" maxlength="72"></label>';
  modal(`<div class="admin-danger-zone danger-dialog"><h2>${titles[action]}</h2><p><strong>${esc(u.display_name)} · #${esc(u.id)}</strong><br>${esc(u.email)}</p><p>${descriptions[action]}</p><form data-danger-form="mutate"><input type="hidden" name="action" value="${action}"><input type="hidden" name="user_id" value="${esc(u.id)}">${extra}<label>Motivo<input name="reason" minlength="5" maxlength="255" required></label><label class="checkbox-label"><input type="checkbox" name="confirm" required> Conferi o participante e as consequências desta ação.</label><label>Minha senha de Administrador<input name="password" type="password" autocomplete="current-password" required></label><button class="primary">Confirmar ${titles[action].toLowerCase()}</button></form></div>`);
  preview(dialogContent.querySelector('form'));
 }
 function preview(form){
  if(!form)return;
  const fine=form.querySelector('[data-fine-preview]');
  if(fine){const value=form.querySelector('[name=amount]').value;if(!/^(0|[1-9][0-9]*)(\.[0-9]{1,2})?$/.test(value)){fine.textContent='Informe um valor positivo com até duas casas decimais.';return;}const parts=value.split('.');const units=BigInt(parts[0]+(parts[1]||'').padEnd(2,'0'));const rest=BigInt(selected.balances.MR.available)-units;fine.textContent=units<=0n?'A multa deve ser positiva.':rest<0n?'Saldo insuficiente: a multa será recusada integralmente.':'Saldo previsto após multa: '+amount(rest.toString());}
  const ban=form.querySelector('[data-ban-preview]');if(ban){const minutes=Number(form.querySelector('[name=minutes]').value);ban.textContent=Number.isInteger(minutes)&&minutes>0&&minutes<=525600?'Término previsto: '+date(new Date(Date.now()+minutes*60000).toISOString())+' · São Paulo':'Informe duração válida.';}
 }
 async function recover(){
  const unknown=state.unknown;if(!unknown||!unknown.action.startsWith('admin.danger.'))return;
  const result=await api(`admin.danger.result&operation_action=${encodeURIComponent(unknown.action)}&key=${encodeURIComponent(unknown.key)}`);
  state.unknown=null;dialog.close();toast('Resultado confirmado. Recibo '+result.operation_id);await loadAdmin();await select(result.user_id);
 }
 document.addEventListener('input',e=>{if(e.target.closest('[data-danger-form="mutate"]'))preview(e.target.form);});
 document.addEventListener('click',async e=>{
  const el=e.target.closest('[data-danger-user],[data-danger-action],[data-danger-recover],[data-danger-page]');if(!el)return;
  try{if(el.hasAttribute('data-danger-page'))await select(selected.u.id,Number(el.dataset.dangerPage));else if(el.hasAttribute('data-danger-user'))await select(el.dataset.dangerUser);else if(el.hasAttribute('data-danger-recover'))await recover();else open(el.dataset.dangerAction);}catch(err){toast(err.message,true);}
 });
 document.addEventListener('submit',async e=>{
  const form=e.target;if(!form.dataset.dangerForm)return;e.preventDefault();
  try{
   if(state.user?.role!=='admin'||state.busy)return;
   if(form.dataset.dangerForm==='lookup'){await select(new FormData(form).get('user_id'));return;}
   const d=Object.fromEntries(new FormData(form));const action='admin.danger.'+d.action;
   form.dataset.key||=crypto.randomUUID();const key=form.dataset.key;
   if(state.unknown&&(state.unknown.key!==key||state.unknown.action!==action))throw Error('Consulte a operação anterior antes de prosseguir.');
   const payload=state.unknown?{...state.unknown.safePayload}:{user_id:d.user_id,reason:d.reason,confirm:form.querySelector('[name=confirm]').checked};
   if(!state.unknown){if(d.amount!==undefined)payload.amount=d.amount;if(d.minutes!==undefined)payload.until=new Date(Date.now()+Number(d.minutes)*60000).toISOString();if(d.confirmation!==undefined)payload.confirmation=d.confirmation;}
   const safePayload={...payload};payload.password=d.password;
   if(d.action==='password'){payload.new_password=d.new_password;payload.new_password_confirmation=d.new_password_confirmation;}
   form.querySelectorAll('input[type=password]').forEach(input=>input.value='');
   state.busy=true;form.querySelectorAll('button').forEach(b=>b.disabled=true);
   try{const result=await api(action,payload,key);state.unknown=null;dialog.close();toast('Ação registrada. Recibo '+result.operation_id);state.busy=false;await loadAdmin();await select(result.user_id);}
   catch(err){
    if(err.network){state.unknown={action,key,safePayload};if(!form.querySelector('[data-danger-recover],[data-danger-page]')){const b=document.createElement('button');b.type='button';b.className='secondary';b.dataset.dangerRecover='1';b.textContent='Consultar resultado';form.append(b);}throw Error('Resultado desconhecido. Consulte o recibo; se ainda não encontrado, informe novamente as senhas e reenvie com a mesma chave. Os dados originais serão preservados.');}
    if(!state.unknown)delete form.dataset.key;throw err;
   }finally{state.busy=false;form.querySelectorAll('button').forEach(b=>b.disabled=false);}
  }catch(err){errorIn(form,err.message);}
 });
 return {select};
}
