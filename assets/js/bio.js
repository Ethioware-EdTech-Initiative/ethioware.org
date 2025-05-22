document.addEventListener("DOMContentLoaded", () => {
    const menuToggle = document.querySelector("#nav-toggle");
    const menu = document.querySelector("#nav-menu");
    const menuClose = document.querySelector("#nav-close");







    menuToggle.addEventListener("click", e => {
        menu.classList.add("show-menu");
    });
    menuClose.addEventListener("click", e => {
        menu.classList.remove("show-menu");
    });
});