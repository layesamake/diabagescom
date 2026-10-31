(function(){
class DCLogic{constructor(p){this.props=p||{};this.state={};}setState(s){Object.assign(this.state,typeof s==='function'?s(this.state):s);if(window.__dcRender){window.__dcRender();}}forceUpdate(){if(window.__dcRender){window.__dcRender();}}}
window.DCLogic=DCLogic;
function look(path,scope){path=path.trim();if(path==='true')return true;if(path==='false')return false;if(/^-?\d+(\.\d+)?$/.test(path))return +path;var v=scope;path.split('.').forEach(function(k){v=v==null?undefined:v[k];});return v;}
function interp(s,scope){return s.replace(/\{\{\s*([^}]+?)\s*\}\}/g,function(_,p){var v=look(p,scope);return v==null?'':String(v);});}
function proc(node,scope,out){
 if(node.nodeType===3){out.appendChild(document.createTextNode(interp(node.nodeValue,scope)));return;}
 if(node.nodeType!==1)return;
 var tag=node.tagName.toLowerCase();
 if(tag==='helmet')return;
 if(tag==='sc-for'){var list=look(node.getAttribute('list').replace(/[{}]/g,''),scope)||[];var as=node.getAttribute('as');list.forEach(function(it,i){var sc=Object.assign({},scope);sc[as]=it;sc.$index=i;node.childNodes.forEach(function(c){proc(c,sc,out);});});return;}
 if(tag==='sc-if'){if(look(node.getAttribute('value').replace(/[{}]/g,''),scope)){node.childNodes.forEach(function(c){proc(c,scope,out);});}return;}
 var el=node.namespaceURI==='http://www.w3.org/1999/xhtml'?document.createElement(node.localName):document.createElementNS(node.namespaceURI,node.localName);
 Array.from(node.attributes).forEach(function(a){var m=a.value.match(/^\{\{\s*([^}]+?)\s*\}\}$/);
  if(a.name.toLowerCase()==='onclick'){if(m){var fn=look(m[1],scope);if(typeof fn==='function')el.addEventListener('click',function(e){e.preventDefault();fn();});}return;}
  if(a.name.indexOf('hint-')===0)return;
  if(m){var v=look(m[1],scope);if(v!==undefined&&v!==false)el.setAttribute(a.name,String(v));}else el.setAttribute(a.name,interp(a.value,scope));});
 node.childNodes.forEach(function(c){proc(c,scope,el);});
 out.appendChild(el);}
document.addEventListener('DOMContentLoaded',function(){
 var x=document.querySelector('x-dc');var tpl=document.createElement('template');tpl.innerHTML=x.innerHTML;
 var hs=tpl.content.querySelector('helmet');if(hs){hs.querySelectorAll('style').forEach(function(s){document.head.appendChild(s.cloneNode(true));});}
 var st=document.createElement('style');st.textContent=[400,600,700,800].map(function(w){return "@font-face{font-family:'Atkinson Hyperlegible Next';font-weight:"+w+";src:url(atkinson-hyperlegible-next-latin-"+w+"-normal.woff2) format('woff2');}";}).join('');document.head.appendChild(st);
 var code=document.querySelector('script[data-dc-script]').textContent;// oxlint-disable-next-line typescript/no-implied-eval -- Maquettes locales, code contrôlé dans la même page.
 var C=new Function('DCLogic',code+';return Component;')(DCLogic);var comp=new C({});
 var root=document.createElement('div');root.id='dcroot';x.replaceWith(root);
 window.__dcRender=function(){var vals=comp.renderVals();var f=document.createDocumentFragment();tpl.content.childNodes.forEach(function(c){proc(c,vals,f);});root.innerHTML='';root.appendChild(f);};
 window.__dcRender();});
})();
