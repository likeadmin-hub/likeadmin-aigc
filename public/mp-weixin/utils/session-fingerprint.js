"use strict";exports.sessionFingerprint=function(t){let e=2166136261;for(let r=0;r<t.length;r++)e=Math.imul(e^t.charCodeAt(r),16777619);return(e>>>0).toString(36)};
