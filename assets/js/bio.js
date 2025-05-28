document.addEventListener("DOMContentLoaded", () => {
    const menuToggle = document.querySelector("#nav-toggle");
    const menu = document.querySelector("#nav-menu");
    const menuClose = document.querySelector("#nav-close");
    const desc = document.querySelectorAll(".job-desc");
    const descToggle = document.querySelectorAll(".tag-close");
    const jobs = document.querySelectorAll(".job");

    menuToggle.addEventListener("click", e => {
        menu.classList.add("show-menu");
    });
    menuClose.addEventListener("click", e => {
        menu.classList.remove("show-menu");
    });
    jobs.forEach((job, ind) => {
        job.addEventListener("click", e => {
            desc[ind].classList.toggle("hidden");
            descToggle[ind].classList.toggle("turn");
        });
    });
});