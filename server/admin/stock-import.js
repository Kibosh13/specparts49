'use strict';
// Used by the import dialog and its integration fixture. Files stay in the browser until preview is accepted.
window.SP49_readStockFile=async function(file,{headerless=false}={}){
 if(file.size>5*1024*1024)throw Error('Файл больше 5 МБ.');
 const bytes=await file.arrayBuffer();
 if(/\.xlsx?$/i.test(file.name)){
  if(!window.XLSX)throw Error('Модуль Excel не загрузился. Обновите страницу.');
  const workbook=XLSX.read(bytes,{type:'array',cellText:true,cellDates:false,sheetRows:10002,sheets:0});
  const sheet=workbook.Sheets[workbook.SheetNames[0]];
  if(!sheet?.['!ref'])throw Error('В файле нет таблицы.');
  const range=XLSX.utils.decode_range(sheet['!fullref']||sheet['!ref']);
  if(range.e.r>=10000+(headerless?0:1))throw Error('За один импорт можно загрузить до 10 000 строк.');
  if(headerless&&(range.s.c!==0||range.e.c!==1))throw Error('Для файла без заголовков оставьте два столбца: артикул в A и наименование в B.');
  const csv=XLSX.utils.sheet_to_csv(sheet,{FS:';',blankrows:false,rawNumbers:false});
  return headerless?'Артикул;Наименование\n'+csv:csv;
 }
 if(headerless)throw Error('Режим без заголовков предназначен для Excel.');
 let text=new TextDecoder('utf-8',{fatal:false}).decode(bytes);
 if(text.includes('\uFFFD'))text=new TextDecoder('windows-1251').decode(bytes);
 return text;
};
