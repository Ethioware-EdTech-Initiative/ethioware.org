document.addEventListener("DOMContentLoaded", () => {
  const wrapper = document.getElementById("partner-wrapper");
  const content = wrapper.innerHTML;
  wrapper.innerHTML = content.repeat(3);

  const contentWidth = wrapper.scrollWidth / 2;
  const animationDuration = contentWidth / 80;

  wrapper.style.setProperty("--animation-duration", `${animationDuration}s`);
  wrapper.style.animation = `scroll var(--animation-duration) linear infinite`;
});
