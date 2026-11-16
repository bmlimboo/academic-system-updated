(function(){
 if(window.__agSticky)return;window.__agSticky=true;
 function measure(table){
  var rows=table.tHead?table.tHead.rows:[],top=0,i,h;
  for(i=0;i<rows.length;i++){rows[i].style.setProperty('--ag-top',top+'px');h=rows[i].getBoundingClientRect().height;top+=h;}
  var cells=rows.length>1?rows[1].cells:[];
  if(cells.length>=3){
   var w1=cells[0].getBoundingClientRect().width,w2=cells[1].getBoundingClientRect().width;
   table.style.setProperty('--ag-l2',w1+'px');table.style.setProperty('--ag-l3',(w1+w2)+'px');
  }
 }
 function all(){[].forEach.call(document.querySelectorAll('table.attendance-grid'),measure);}
 var queued=false;function soon(){if(queued)return;queued=true;requestAnimationFrame(function(){queued=false;all();});}
 function start(){
  all();window.addEventListener('resize',soon);
  document.addEventListener('toggle',soon,true);
  if(document.fonts&&document.fonts.ready)document.fonts.ready.then(soon);
  if('ResizeObserver' in window){var ro=new ResizeObserver(soon);[].forEach.call(document.querySelectorAll('table.attendance-grid'),function(t){ro.observe(t);});}
 }
 if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',start);else start();
})();
