import React, { useState, useEffect } from 'react';
import { CircleArrowUp } from 'lucide-react';

export default function ScrollArriba() {
  const [visible, setVisible] = useState(false);

  useEffect(() => {
    const onScroll = () => {
      setVisible(window.scrollY > 300); // muestra tras 300px
    };
    window.addEventListener('scroll', onScroll);
    return () => window.removeEventListener('scroll', onScroll);
  }, []);

  const scrollUp = () => window.scrollTo({ top: 0, behavior: 'smooth' });

  return visible ? (
    <button className="scroll-top" onClick={scrollUp} aria-label="Volver arriba">
      <CircleArrowUp size={36} />
    </button>
  ) : null;
}
