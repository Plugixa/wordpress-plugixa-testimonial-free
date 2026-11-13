import{r as e}from"./rolldown-runtime-hePW80VL.js";import{m as t}from"./vendor-dnd-DXdIZbLR.js";import{_ as n}from"./api-vTrtANSr.js";import{C as r,E as i,Et as a,F as o,K as s,M as c,N as l,P as u,d,it as f,j as p,l as m,q as h,rt as g,vt as _,x as v,yt as y}from"./useTranslation-DWkm9T6I.js";import{R as b,w as x}from"./MenuItem-CsZySsSX.js";import{h as S}from"./vendor-recharts-BAsxCiww.js";import{n as C}from"./FormControlLabel-CZTWn2AH.js";var w=e(t(),1),T=a();function E(e){return f(`MuiCheckbox`,e)}var D=g(`MuiCheckbox`,[`root`,`checked`,`disabled`,`indeterminate`,`colorPrimary`,`colorSecondary`,`sizeSmall`,`sizeMedium`]),O=v((0,T.jsx)(`path`,{d:`M19 5v14H5V5h14m0-2H5c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2z`}),`CheckBoxOutlineBlank`),k=v((0,T.jsx)(`path`,{d:`M19 3H5c-1.11 0-2 .9-2 2v14c0 1.1.89 2 2 2h14c1.11 0 2-.9 2-2V5c0-1.1-.89-2-2-2zm-9 14l-5-5 1.41-1.41L10 14.17l7.59-7.59L19 8l-9 9z`}),`CheckBox`),A=v((0,T.jsx)(`path`,{d:`M19 3H5c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2zm-2 10H7v-2h10v2z`}),`IndeterminateCheckBox`),j=e=>{let{classes:t,indeterminate:n,color:r,size:i}=e,a={root:[`root`,n&&`indeterminate`,`color${p(r)}`,`size${p(i)}`]},o=h(a,E,t);return{...t,...o}},M=u(C,{shouldForwardProp:e=>o(e)||e===`classes`,name:`MuiCheckbox`,slot:`Root`,overridesResolver:(e,t)=>{let{ownerState:n}=e;return[t.root,n.indeterminate&&t.indeterminate,t[`size${p(n.size)}`],n.color!=="default"&&t[`color${p(n.color)}`]]}})(l(({theme:e})=>({color:(e.vars||e).palette.text.secondary,...e.focusVisible&&{[`&.${d.focusVisible} svg:first-of-type`]:{...s,borderRadius:(e.vars||e).shape.borderRadius,...e.focusVisible}},variants:[{props:{color:`default`,disableRipple:!1},style:{"&:hover":{backgroundColor:e.alpha((e.vars||e).palette.action.active,(e.vars||e).palette.action.hoverOpacity)}}},...Object.entries(e.palette).filter(m()).map(([t])=>({props:{color:t,disableRipple:!1},style:{"&:hover":{backgroundColor:e.alpha((e.vars||e).palette[t].main,(e.vars||e).palette.action.hoverOpacity)}}})),...Object.entries(e.palette).filter(m()).map(([t])=>({props:{color:t},style:{[`&.${D.checked}, &.${D.indeterminate}`]:{color:(e.vars||e).palette[t].main},[`&.${D.disabled}`]:{color:(e.vars||e).palette.action.disabled}}})),{props:{disableRipple:!1},style:{"&:hover":{"@media (hover: none)":{backgroundColor:`transparent`}}}}]}))),N=(0,T.jsx)(k,{}),P=(0,T.jsx)(O,{}),F=(0,T.jsx)(A,{}),I=w.forwardRef(function(e,t){let r=c({props:e,name:`MuiCheckbox`}),{checkedIcon:i=N,color:a=`primary`,icon:o=P,indeterminate:s=!1,indeterminateIcon:l=F,size:u=`medium`,disableRipple:d=!1,className:f,slots:p={},slotProps:m={},...h}=r,g=s?l:o,_=s?l:i,v={...r,disableRipple:d,color:a,indeterminate:s,size:u},y=j(v),b=m.input,[C,E]=n(`root`,{ref:t,elementType:M,className:S(y.root,f),shouldForwardComponentProp:!0,externalForwardedProps:{slots:p,slotProps:m,...h},ownerState:v,additionalProps:{type:`checkbox`,icon:w.cloneElement(g,{fontSize:g.props.fontSize??u}),checkedIcon:w.cloneElement(_,{fontSize:_.props.fontSize??u}),disableRipple:r.disableRipple,slots:p,slotProps:{input:x(typeof b==`function`?b(v):b,{"data-indeterminate":s,"aria-checked":s?`mixed`:void 0})}}});return(0,T.jsx)(C,{...E,classes:y})});function L(e){return f(`MuiLinearProgress`,e)}g(`MuiLinearProgress`,[`root`,`colorPrimary`,`colorSecondary`,`determinate`,`indeterminate`,`buffer`,`query`,`dashed`,`bar`,`bar1`,`bar2`]);var R=4,z={},B=y`
  0% {
    left: -35%;
    right: 100%;
  }

  60% {
    left: 100%;
    right: -90%;
  }

  100% {
    left: 100%;
    right: -90%;
  }
`,V=typeof B==`string`?null:_`
        animation: ${B} 2.1s cubic-bezier(0.65, 0.815, 0.735, 0.395) infinite;
      `,H=y`
  0% {
    left: -200%;
    right: 100%;
  }

  60% {
    left: 107%;
    right: -8%;
  }

  100% {
    left: 107%;
    right: -8%;
  }
`,U=typeof H==`string`?null:_`
        animation: ${H} 2.1s cubic-bezier(0.165, 0.84, 0.44, 1) 1.15s infinite;
      `,W=y`
  0% {
    opacity: 1;
    background-position: 0 -23px;
  }

  60% {
    opacity: 0;
    background-position: 0 -23px;
  }

  100% {
    opacity: 1;
    background-position: -200px -23px;
  }
`,G=typeof W==`string`?null:_`
        animation: ${W} 3s infinite linear;
      `,K=e=>{let{classes:t,variant:n,color:r}=e,i={root:[`root`,`color${p(r)}`,n],dashed:[`dashed`],bar1:[`bar`,`bar1`],bar2:[`bar`,`bar2`,n===`buffer`&&`color${p(r)}`]};return h(i,L,t)},q=(e,t)=>e.vars?e.vars.palette.LinearProgress[`${t}Bg`]:e.palette.mode===`light`?e.lighten(e.palette[t].main,.62):e.darken(e.palette[t].main,.5),J=u(`span`,{name:`MuiLinearProgress`,slot:`Root`,overridesResolver:(e,t)=>{let{ownerState:n}=e;return[t.root,t[`color${p(n.color)}`],t[n.variant]]}})(l(({theme:e})=>({position:`relative`,overflow:`hidden`,display:`block`,height:4,zIndex:0,"@media print":{colorAdjust:`exact`},variants:[...Object.entries(e.palette).filter(m()).map(([t])=>({props:{color:t},style:{backgroundColor:q(e,t)}})),{props:({ownerState:e})=>e.color===`inherit`&&e.variant!==`buffer`,style:{"&::before":{content:`""`,position:`absolute`,left:0,top:0,right:0,bottom:0,backgroundColor:`currentColor`,opacity:.3}}},{props:{variant:`buffer`},style:{backgroundColor:`transparent`}},{props:{variant:`query`},style:{transform:`rotate(180deg)`}}]}))),Y=u(`span`,{name:`MuiLinearProgress`,slot:`Dashed`})(l(({theme:e})=>({position:`absolute`,marginTop:0,height:`100%`,width:`100%`,backgroundSize:`10px 10px`,backgroundPosition:`0 -23px`,variants:[{props:{color:`inherit`},style:{opacity:.3,backgroundImage:`radial-gradient(currentColor 0%, currentColor 16%, transparent 42%)`}},...Object.entries(e.palette).filter(m()).map(([t])=>{let n=q(e,t);return{props:{color:t},style:{backgroundImage:`radial-gradient(${n} 0%, ${n} 16%, transparent 42%)`}}})]})),G||{animation:`${W} 3s infinite linear`},l(({theme:e})=>r(e,{animation:`none`})||z)),X=u(`span`,{name:`MuiLinearProgress`,slot:`Bar1`,overridesResolver:(e,t)=>[t.bar,t.bar1]})(l(({theme:e})=>{let t=r(e,{animation:`none`,left:`30%`,right:`auto`,width:`40%`});return{width:`100%`,position:`absolute`,left:0,bottom:0,top:0,...i(e,`transform`,{duration:`0.2s`,easing:`linear`}),transformOrigin:`left`,variants:[{props:{color:`inherit`},style:{backgroundColor:`currentColor`}},...Object.entries(e.palette).filter(m()).map(([t])=>({props:{color:t},style:{backgroundColor:(e.vars||e).palette[t].main}})),{props:{variant:`determinate`},style:{...i(e,`transform`,{duration:`.${R}s`,easing:`linear`})}},{props:{variant:`buffer`},style:{zIndex:1,...i(e,`transform`,{duration:`.${R}s`,easing:`linear`})}},{props:({ownerState:e})=>e.variant===`indeterminate`||e.variant===`query`,style:{width:`auto`}},{props:({ownerState:e})=>e.variant===`indeterminate`||e.variant===`query`,style:V||{animation:`${B} 2.1s cubic-bezier(0.65, 0.815, 0.735, 0.395) infinite`}},...t?[{props:({ownerState:e})=>e.variant===`indeterminate`||e.variant===`query`,style:t}]:[]]}})),Z=u(`span`,{name:`MuiLinearProgress`,slot:`Bar2`,overridesResolver:(e,t)=>[t.bar,t.bar2]})(l(({theme:e})=>{let t=r(e,{animation:`none`,display:`none`});return{width:`100%`,position:`absolute`,left:0,bottom:0,top:0,...i(e,`transform`,{duration:`0.2s`,easing:`linear`}),transformOrigin:`left`,variants:[...Object.entries(e.palette).filter(m()).map(([t])=>({props:{color:t},style:{"--LinearProgressBar2-barColor":(e.vars||e).palette[t].main}})),{props:({ownerState:e})=>e.variant!==`buffer`&&e.color!==`inherit`,style:{backgroundColor:`var(--LinearProgressBar2-barColor, currentColor)`}},{props:({ownerState:e})=>e.variant!==`buffer`&&e.color===`inherit`,style:{backgroundColor:`currentColor`}},{props:{color:`inherit`},style:{opacity:.3}},...Object.entries(e.palette).filter(m()).map(([t])=>({props:{color:t,variant:`buffer`},style:{backgroundColor:q(e,t),...i(e,`transform`,{duration:`.${R}s`,easing:`linear`})}})),{props:({ownerState:e})=>e.variant===`indeterminate`||e.variant===`query`,style:{width:`auto`}},{props:({ownerState:e})=>e.variant===`indeterminate`||e.variant===`query`,style:U||{animation:`${H} 2.1s cubic-bezier(0.165, 0.84, 0.44, 1) 1.15s infinite`}},...t?[{props:({ownerState:e})=>e.variant===`indeterminate`||e.variant===`query`,style:t}]:[]]}})),Q=w.forwardRef(function(e,t){let n=c({props:e,name:`MuiLinearProgress`}),{className:r,color:i=`primary`,max:a,min:o,value:s,valueBuffer:l,variant:u=`indeterminate`,...d}=n,f={...n,color:i,variant:u},p=o??0,m=a??100,h=K(f),g=b(),_={},v={bar1:{},bar2:{}};if((u===`determinate`||u===`buffer`)&&s!==void 0){let e=m-p,t=(s-p)/e*100-100;g&&(t=-t),v.bar1.transform=e>0?`translateX(${t}%)`:`translateX(-100%)`,_[`aria-valuenow`]=s,_[`aria-valuemin`]=p,_[`aria-valuemax`]=m}if(u===`buffer`&&l!==void 0){let e=m-p,t=(l-p)/e*100-100;g&&(t=-t),v.bar2.transform=e>0?`translateX(${t}%)`:`translateX(-100%)`}return(0,T.jsxs)(J,{className:S(h.root,r),ownerState:f,role:`progressbar`,..._,ref:t,...d,children:[u===`buffer`?(0,T.jsx)(Y,{className:h.dashed,ownerState:f}):null,(0,T.jsx)(X,{className:h.bar1,ownerState:f,style:v.bar1}),u===`determinate`?null:(0,T.jsx)(Z,{className:h.bar2,ownerState:f,style:v.bar2})]})});export{I as n,Q as t};
