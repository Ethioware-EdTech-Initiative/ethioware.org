document.addEventListener("DOMContentLoaded", () => {
  const wrapper = document.getElementById("partner-wrapper");

  // Check if wrapper has content before accessing scrollWidth
  if (wrapper.scrollWidth > 0) {
    const content = wrapper.innerHTML;
    wrapper.innerHTML = content.repeat(10);

    const contentWidth = wrapper.scrollWidth; // Access scrollWidth after content is added
    const animationDuration = contentWidth / 100; // Adjust divisor for desired speed

    wrapper.style.setProperty("--animation-duration", `${animationDuration}s`);
    wrapper.style.animation = `scroll var(--animation-duration) linear infinite`;
  } else {
    console.warn("Partner wrapper has no content. Animation not applied.");
  }
});
