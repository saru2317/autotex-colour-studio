import{mkdir,copyFile}from'node:fs/promises';
await mkdir('public/cad',{recursive:true});
for(const name of ['occt-import-js.js','occt-import-js.wasm'])await copyFile('node_modules/occt-import-js/dist/'+name,'public/cad/'+name);
await copyFile('node_modules/occt-import-js/LICENSE.md','public/cad/LICENSE.md');
