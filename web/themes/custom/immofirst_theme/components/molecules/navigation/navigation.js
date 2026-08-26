document.addEventListener('DOMContentLoaded',()=>{
  document.querySelectorAll('.navigation [aria-expanded]').forEach(btn=>{
    btn.addEventListener('click',()=>{
      const expanded=btn.getAttribute('aria-expanded')==='true';
      btn.setAttribute('aria-expanded',String(!expanded));
      const submenu=btn.nextElementSibling;
      if(submenu){submenu.hidden=expanded;}
    });
  });
});
