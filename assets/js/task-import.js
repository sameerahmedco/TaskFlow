/* TaskFlow v3: standalone, safe CSV parsing and preview validation. */
(function (root) {
  'use strict';
  const names=['title','description','priority','due_date','estimate_hours'];
  function parse(text) {
    if (typeof text !== 'string' || new TextEncoder().encode(text).length > 128*1024) {
      throw new Error('Choose a CSV file no larger than 128 KB.');
    }
    text=text.replace(/^\uFEFF/,'');
    const records=[];
    let fields=[],value='',quoted=false,closed=false,fieldStarted=false;
    function pushField(){fields.push(value);value='';closed=false;fieldStarted=false;}
    function pushRecord(){pushField();if(fields.some(field=>field.trim()!=='')) records.push(fields);fields=[];}
    for (let i=0;i<text.length;i++) {
      const ch=text[i];
      if (quoted) {
        if (ch==='"') { if (text[i+1]==='"') {value+='"';i++;} else {quoted=false;closed=true;} }
        else value+=ch;
        continue;
      }
      if (ch==='"') {
        if (fieldStarted || closed || value) throw new Error('CSV has an unexpected quote near character '+(i+1)+'.');
        quoted=true;fieldStarted=true;continue;
      }
      if (ch===',') {pushField();continue;}
      if (ch==='\n'||ch==='\r') {if(ch==='\r'&&text[i+1]==='\n')i++;pushRecord();continue;}
      if (closed) throw new Error('Only a comma or line ending may follow a closing quote.');
      value+=ch;fieldStarted=true;
    }
    if(quoted)throw new Error('CSV contains an unfinished quoted field.');
    if (fields.length || value || fieldStarted || closed) pushRecord();
    if (!records.length) throw new Error('CSV is empty.');
    const header=records.shift().map(h=>h.trim().toLowerCase());
    if(!header.includes('title')) throw new Error('CSV needs a title column.');
    if(new Set(header).size!==header.length || header.some(h=>!names.includes(h))) {
      throw new Error('Use only these unique columns: '+names.join(', ')+'.');
    }
    if(records.length===0||records.length>50)throw new Error('CSV must contain 1–50 task rows.');
    const rows=records.map((columns,i)=>{
      if(columns.length!==header.length)throw new Error('Row '+(i+2)+' has the wrong number of columns.');
      const row={title:'',description:'',priority:'none',due_date:'',estimate_hours:''};
      for(let j=0;j<header.length;j++)row[header[j]]=columns[j].trim();
      if(!row.title || new TextEncoder().encode(row.title).length>255)throw new Error('Row '+(i+2)+' needs a title of 1–255 bytes.');
      if(new TextEncoder().encode(row.description).length>5000)throw new Error('Row '+(i+2)+' description exceeds 5,000 bytes.');
      if(!['none','low','normal','high','urgent',''].includes(row.priority))throw new Error('Row '+(i+2)+' has an invalid priority.');
      if(row.due_date && (!/^\d{4}-\d{2}-\d{2}$/.test(row.due_date)
         || !Number.isFinite(Date.parse(row.due_date+'T00:00:00Z'))
         || new Date(row.due_date+'T00:00:00Z').toISOString().slice(0,10)!==row.due_date
         || row.due_date<'2000-01-01' || row.due_date>'2100-12-31')) throw new Error('Row '+(i+2)+' has an invalid due date.');
      if(row.estimate_hours && (!/^(?:0|[1-9]\d{0,2})(?:\.\d{1,4})?$/.test(row.estimate_hours)
          || !(Number(row.estimate_hours)*60>=0.5 && Number(row.estimate_hours)<=400))) throw new Error('Row '+(i+2)+' has invalid estimated hours.');
      if(/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/.test(row.title+'\n'+row.description))throw new Error('Row '+(i+2)+' contains control characters.');
      if(row.priority==='')row.priority='none';
      return row;
    });
    return rows;
  }
  const api=Object.freeze({parse});
  if(typeof module==='object' && module.exports)module.exports=api;
  if(root)root.TaskFlowCSV=api;
})(typeof window==='object'?window:null);
