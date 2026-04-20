-- Janela intradiária para repetições (início/fim) + suporte em instalações antigas
USE track_app;

ALTER TABLE habits
    ADD COLUMN IF NOT EXISTS intraday_window_start TIME NULL AFTER intraday_every_unit,
    ADD COLUMN IF NOT EXISTS intraday_window_end TIME NULL AFTER intraday_window_start;

-- Opcional: preenche hábitos antigos com janela padrão comercial (09:00-18:00)
UPDATE habits
SET
    intraday_window_start = COALESCE(intraday_window_start, '09:00:00'),
    intraday_window_end = COALESCE(intraday_window_end, '18:00:00');
