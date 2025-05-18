import React, { useState, useRef, useEffect } from 'react';

const placeholderImg = process.env.REACT_APP_QUIENES_IMG;
const googleLogo = process.env.REACT_APP_GOOGLE_IMG;

const reviews = [
  {
    id: 1,
    name: 'José Antonio',
    date: '2025-05-13',
    rating: 5,
    text: 'Recomendable al 100%. Equipo muy profesional y amigable. Me ayudaron mucho durante mi tratamiento. Los echaré de menos ;)',
    avatar: placeholderImg
  },
  {
    id: 2,
    name: 'Luis Pérez',
    date: '2025-05-12',
    rating: 1,
    text: 'Muy profesionales, aunque el segundo mes tuvimos algún retraso.',
    avatar: placeholderImg
  },
  {
    id: 3,
    name: 'María López',
    date: '2025-05-13',
    rating: 3,
    text: '¡Recomendable 100%! Mi pronunciación mejoró un montón.',
    avatar: placeholderImg
  },
   {
    id: 4,
    name: 'Ana García',
    date: '2025-05-13',
    rating: 2,
    text: 'Excelente trato y resultados maravillosos con mi hijo.',
    avatar: placeholderImg
  }, 
  {
    id: 5,
    name: 'Ana García',
    date: '2025-05-11',
    rating: 4,
    text: 'Excelente trato y resultados maravillosos con mi hijo.',
    avatar: placeholderImg
  },
    {
    id: 6,
    name: 'José Antonio',
    date: '2025-05-13',
    rating: 5,
    text: 'Recomendable al 100%. Equipo muy profesional y amigable. Me ayudaron mucho durante mi tratamiento. Los echaré de menos ;)',
    avatar: placeholderImg
  },
  {
    id: 7,
    name: 'Luis Pérez',
    date: '2025-05-12',
    rating: 1,
    text: 'Muy profesionales, aunque el segundo mes tuvimos algún retraso.',
    avatar: placeholderImg
  },
  {
    id: 8,
    name: 'María López',
    date: '2025-05-13',
    rating: 3,
    text: '¡Recomendable 100%! Mi pronunciación mejoró un montón.',
    avatar: placeholderImg
  },
   {
    id: 9,
    name: 'Ana García',
    date: '2025-05-13',
    rating: 2,
    text: 'Excelente trato y resultados maravillosos con mi hijo.',
    avatar: placeholderImg
  }, 
  {
    id: 10,
    name: 'Ana García',
    date: '2025-05-11',
    rating: 4,
    text: 'Excelente trato y resultados maravillosos con mi hijo.',
    avatar: placeholderImg
  }
];

export default function Resenas() {
  const [current, setCurrent] = useState(0);
  const sliderRef = useRef();

  const prev = () => setCurrent(c => Math.max(c - 1, 0));
  const next = () => setCurrent(c => Math.min(c + 1, reviews.length - 1));

  // scroll cuando cambia current
  useEffect(() => {
    const cont = sliderRef.current;
    if (!cont) return;
    const cardW = cont.firstChild.offsetWidth + 24;
    cont.scrollTo({ left: cardW * current, behavior: 'smooth' });
  }, [current]);

  // autoplay cada 5s
  useEffect(() => {
    const id = setInterval(() => {
      setCurrent(c => (c + 1) % reviews.length);
    }, 5000);
    return () => clearInterval(id);
  }, []);

  return (
    <section id="resenas" className="resenas">
      <h2 className="resenas__header">Reseñas</h2>
      <div className="resenas__slider-container">
        <button
          className="resenas__nav resenas__nav--prev"
          onClick={prev}
          disabled={current === 0}
        >&larr;</button>
        <div className="resenas__slider" ref={sliderRef}>
          {reviews.map(r => (
            <div key={r.id} className="resenas__card">
              <div className="resenas__card-header">
                <img src={r.avatar} alt={r.name} className="resenas__avatar" />
                <div className="resenas__user-info">
                  <span className="resenas__name">{r.name}</span>
                  <span className="resenas__date">{r.date}</span>
                </div>
                <img src={googleLogo} alt="Google" className="resenas__google-logo" />
              </div>
              <div className="resenas__rating">
                {Array.from({ length: 5 }, (_, i) =>
                  <span
                    key={i}
                    className={i < r.rating ? 'resenas__star resenas__star--filled' : 'resenas__star'}
                  >★</span>
                )}
              </div>
              <p className="resenas__text">{r.text}</p>
            </div>
          ))}
        </div>
        <button
          className="resenas__nav resenas__nav--next"
          onClick={next}
          disabled={current === reviews.length - 1}
        >&rarr;</button>
      </div>
    </section>
  );
}