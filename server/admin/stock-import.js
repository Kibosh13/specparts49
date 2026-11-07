'use strict';
// Used by the import dialog and its integration fixture. Files stay in the browser until preview is accepted.
window.SP49_readStockFile=async function(file){
 if(file.size>5*1024*1024)throw Error('Файл больше 5 МБ.');
 const bytes=await file.arrayBuffer();
 if(/\.xlsx?$/i.test(file.name)){
  if(!window.XLSX)throw Error('Модуль Excel не загрузился. Обновите страницу.');
  const workbook=XLSX.read(bytes,{type:'array',cellText:true,cellDates:false,sheetRows:5002,sheets:0});
  const sheet=workbook.Sheets[workbook.SheetNames[0]];
  if(!sheet)throw Error('В файле нет таблицы.');
  return XLSX.utils.sheet_to_csv(sheet,{FS:';',blankrows:false,rawNumbers:false});
 }
 let text=new TextDecoder('utf-8',{fatal:false}).decode(bytes);
 if(text.includes('\uFFFD'))text=new TextDecoder('windows-1251').decode(bytes);
 return text;
};
