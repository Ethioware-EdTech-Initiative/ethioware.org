document.addEventListener("DOMContentLoaded", () => {
  const wrapper = document.getElementById("partner-wrapper");
  const content = wrapper.innerHTML;
  wrapper.innerHTML = content.repeat(10);

  const contentWidth = wrapper.scrollWidth / 1;
  const animationDuration = contentWidth / 100;

  wrapper.style.setProperty("--animation-duration", `${animationDuration}s`);
  wrapper.style.animation = `scroll var(--animation-duration) linear infinite`;
});
