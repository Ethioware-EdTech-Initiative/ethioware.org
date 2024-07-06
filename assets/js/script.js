document.addEventListener("DOMContentLoaded", () => {
  const wrapper = document.getElementById("partner-wrapper");
  const clone = wrapper.innerHTML;
  wrapper.innerHTML = clone + clone + clone;
  const contentWidth = wrapper.scrollWidth / 2;
  const animationDuration = contentWidth / 100;
  wrapper.style.animation = `scroll ${animationDuration}s linear infinite`;
});
