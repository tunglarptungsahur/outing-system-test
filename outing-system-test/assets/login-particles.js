particlesJS('lg-particles', {
  particles: {
    number: { value: 70, density: { enable: true, value_area: 900 } },
    color: { value: '#7b2cbf' },
    shape: { type: 'circle' },
    opacity: { value: 0.55, random: true },
    size: { value: 3, random: true },
    line_linked: { enable: true, distance: 160, color: '#b58ad6', opacity: 0.45, width: 1 },
    move: { enable: true, speed: 1.4, direction: 'none', random: true, straight: false, out_mode: 'out' }
  },
  interactivity: {
    detect_on: 'window',
    events: { onhover: { enable: true, mode: 'grab' }, onclick: { enable: false }, resize: true },
    modes: { grab: { distance: 170, line_linked: { opacity: 0.7 } } }
  },
  retina_detect: true
});
