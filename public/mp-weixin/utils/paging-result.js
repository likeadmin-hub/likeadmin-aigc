"use strict";exports.handlePagingResult=function(r,e){return Promise.resolve(r).catch(r=>{if(r!==`z-paging-${e}-error`)throw r})};
