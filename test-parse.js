const r = "'+966505102616,محمد غالب عطا الله العوفي";
const c = r.replace(/^'/, '');
const p = c.split(',');
console.log({phone: p[0], name: p.slice(1).join(',')});
